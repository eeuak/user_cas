<?php


namespace OCA\UserCAS\Service\Group;


use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Class GroupRenamer
 * @package OCA\UserCAS\Service\Group
 *
 * Renames Nextcloud groups in-place (oc_groups, oc_group_user, oc_share, oc_group_admin)
 * so shares survive normalization-setting changes.
 *
 * Two rename passes:
 *
 *  1. DN-based mapping (automatic): on every import a dn→gid map is persisted.
 *     When normalization settings change the new target name is detected from the map
 *     and the old group is renamed.
 *
 *     For groups that existed before this map was established (first import), the
 *     renamer tries to auto-detect the legacy group by applying the same character
 *     filter WITHOUT umlaut substitution (the most common previous state). If the
 *     result matches an existing Nextcloud group it is treated as the unmapped legacy
 *     group and renamed automatically. The raw trimmed name is tried as a second
 *     candidate (covers cases where the old filter was more permissive).
 *
 *  2. Manual pairs (fallback): app config key 'cas_group_rename_pairs', one "old:new"
 *     per line, for edge cases auto-detection cannot handle. Applied before pass 1.
 */
class GroupRenamer
{
    private const APP_NAME   = 'user_cas';
    private const DN_MAP_KEY = 'cas_group_dn_gid_map';
    private const MANUAL_KEY = 'cas_group_rename_pairs';

    /** @var IConfig */
    private $config;

    /** @var IGroupManager */
    private $groupManager;

    /** @var IDBConnection */
    private $db;

    /** @var LoggerInterface */
    private $logger;

    /** @var OutputInterface|null */
    private $output;

    /** @var list<array{old: string, new: string, source: string}> */
    private $gidChanges = [];

    /** @var list<string>  gids whose displayname was corrected */
    private $displaynameChanges = [];


    public function __construct(IConfig $config, IGroupManager $groupManager, IDBConnection $db, LoggerInterface $logger)
    {
        $this->config       = $config;
        $this->groupManager = $groupManager;
        $this->db           = $db;
        $this->logger       = $logger;
    }


    /**
     * @param array<string, string> $dnToRawName  dn => raw group name from LDAP
     * @param OutputInterface|null  $output        when provided, key events are written live
     * @return array{renamed: int, warnings: list<string>, gid_changes: list<array{old: string, new: string, source: string}>, displayname_changes: list<string>}
     */
    public function renameGroups(array $dnToRawName, ?OutputInterface $output = null): array
    {
        $this->output             = $output;
        $this->gidChanges         = [];
        $this->displaynameChanges = [];

        $result = ['renamed' => 0, 'warnings' => []];

        $manualResult = $this->applyManualPairs();
        $result['renamed']  += $manualResult['renamed'];
        $result['warnings']  = array_merge($result['warnings'], $manualResult['warnings']);

        $dnResult = $this->applyDnMapping($dnToRawName);
        $result['renamed']  += $dnResult['renamed'];
        $result['warnings']  = array_merge($result['warnings'], $dnResult['warnings']);

        $result['gid_changes']         = $this->gidChanges;
        $result['displayname_changes'] = $this->displaynameChanges;

        return $result;
    }


    // -------------------------------------------------------------------------
    // Pass 1: manual pairs
    // -------------------------------------------------------------------------

    /** @return array{renamed: int, warnings: list<string>} */
    private function applyManualPairs(): array
    {
        $raw = trim((string) $this->config->getAppValue(self::APP_NAME, self::MANUAL_KEY, ''));
        $result = ['renamed' => 0, 'warnings' => []];

        if ($raw === '') {
            return $result;
        }

        foreach (preg_split("/[\r\n;]+/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$oldGid, $newGid] = array_map('trim', explode(':', $line, 2));
            if ($oldGid === '' || $newGid === '') {
                continue;
            }

            $outcome = $this->renameOne($oldGid, $newGid, 'manual');
            if ($outcome === true) {
                $result['renamed']++;
            } elseif (is_string($outcome)) {
                $result['warnings'][] = $outcome;
            }
        }

        return $result;
    }


    // -------------------------------------------------------------------------
    // Pass 2: DN-based mapping with auto-detection for unmapped legacy groups
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $dnToRawName
     * @return array{renamed: int, warnings: list<string>}
     */
    private function applyDnMapping(array $dnToRawName): array
    {
        $storedMap  = $this->loadDnMap();
        $updatedMap = [];
        $result     = ['renamed' => 0, 'warnings' => []];

        foreach ($dnToRawName as $dn => $rawName) {
            $newGid = $this->normalizeGroupName($rawName);
            if ($newGid === '') {
                continue;
            }

            $prevGid   = $storedMap[$dn] ?? null;
            $newExists = $this->groupManager->groupExists($newGid);

            if ($prevGid !== null && $prevGid !== $newGid) {
                // Known rename: normalization changed since last import
                $prevExists = $this->groupManager->groupExists($prevGid);

                if ($prevExists && !$newExists) {
                    $outcome = $this->renameOne($prevGid, $newGid, 'DN map');
                    if ($outcome === true) {
                        $updatedMap[$dn] = $newGid;
                        $result['renamed']++;
                    } else {
                        $updatedMap[$dn] = $prevGid;
                        if (is_string($outcome)) $result['warnings'][] = $outcome;
                    }
                } elseif ($prevExists && $newExists) {
                    // renameOne() will handle this: empty target → delete+rename, non-empty → warn
                    $outcome = $this->renameOne($prevGid, $newGid, 'DN map');
                    if ($outcome === true) {
                        $updatedMap[$dn] = $newGid;
                        $result['renamed']++;
                    } else {
                        $updatedMap[$dn] = $prevGid;
                        if (is_string($outcome)) $result['warnings'][] = $outcome;
                    }
                } else {
                    $updatedMap[$dn] = $newGid;
                }

            } elseif ($prevGid === null && !$newExists) {
                // Unmapped and target missing: look for a legacy group created under
                // different normalization settings (e.g. before umlauts were enabled).
                $candidate = $this->findLegacyCandidate($rawName, $newGid);
                if ($candidate !== null) {
                    $outcome = $this->renameOne($candidate, $newGid, 'auto-detect');
                    if ($outcome === true) {
                        $updatedMap[$dn] = $newGid;
                        $result['renamed']++;
                    } else {
                        $updatedMap[$dn] = $candidate;
                        if (is_string($outcome)) $result['warnings'][] = $outcome;
                    }
                } else {
                    // Will be created fresh by the normal import loop
                    $updatedMap[$dn] = $newGid;
                }

            } elseif ($prevGid === null && $newExists) {
                // Target appears to exist — but the DB may match case-insensitively,
                // so "kuenstlerisch…" satisfies groupExists("Kuenstlerisch…").
                // If the matched group is empty it is a phantom; check for a populated
                // legacy group and rename it (renameOne will delete the phantom first).
                $targetGroup  = $this->groupManager->get($newGid);
                $memberCount  = ($targetGroup !== null) ? count($targetGroup->getUsers()) : -1;

                if ($memberCount === 0) {
                    $candidate = $this->findLegacyCandidate($rawName, $newGid);
                    if ($candidate !== null) {
                        $outcome = $this->renameOne($candidate, $newGid, 'auto-detect');
                        if ($outcome === true) {
                            $updatedMap[$dn] = $newGid;
                            $result['renamed']++;
                        } else {
                            $updatedMap[$dn] = $newGid;
                            if (is_string($outcome)) $result['warnings'][] = $outcome;
                        }
                    } else {
                        $updatedMap[$dn] = $newGid;
                    }
                } else {
                    // Target exists and has members — nothing to do
                    $updatedMap[$dn] = $newGid;
                }

            } else {
                // prevGid === newGid — name unchanged
                $updatedMap[$dn] = $newGid;
            }
        }

        $this->saveDnMap($updatedMap);
        $this->syncDisplayNames($updatedMap);
        return $result;
    }


    /**
     * Ensure displayname matches gid for every group in the map.
     *
     * Catches groups whose gid was already updated by a previous import run
     * but whose displayname was never corrected (e.g. before this fix existed).
     *
     * @param array<string, string> $dnToGid
     */
    private function syncDisplayNames(array $dnToGid): void
    {
        $seen = [];
        foreach ($dnToGid as $gid) {
            if ($gid === '' || isset($seen[$gid])) {
                continue;
            }
            $seen[$gid] = true;

            try {
                $qb = $this->db->getQueryBuilder();
                $affected = $qb->update('groups')
                    ->set('displayname', $qb->createNamedParameter($gid))
                    ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
                    ->andWhere($qb->expr()->neq('displayname', $qb->createNamedParameter($gid)))
                    ->executeStatement();

                if ($affected > 0) {
                    $this->writeln(sprintf('  [group-rename] Fixed stale displayname for <info>"%s"</info>.', $gid));
                    $this->logger->info(sprintf("GroupRenamer: corrected displayname for '%s'.", $gid));
                    $this->displaynameChanges[] = $gid;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf("GroupRenamer: could not sync displayname for '%s': %s", $gid, $e->getMessage()));
            }
        }
    }


    /**
     * Try to find an existing Nextcloud group that is a "mangled" version of $rawName
     * produced by an older normalization configuration.
     *
     * Candidates tried in order:
     *  1. Same char filter as current but WITHOUT umlaut replacement — the most
     *     common prior state (ü stripped → "mller" for raw "Müller").
     *  2. The trimmed raw name itself — covers cases where the old filter was
     *     broader and kept special characters as-is.
     */
    private function findLegacyCandidate(string $rawName, string $currentNormalized): ?string
    {
        // Candidate 1: strip-only (no umlaut substitution)
        $stripOnly = $this->normalizeGroupNameStripOnly($rawName);
        if ($stripOnly !== '' && $stripOnly !== $currentNormalized && $this->groupManager->groupExists($stripOnly)) {
            $this->writeln(sprintf(
                '  [group-rename] Auto-detected legacy group <comment>"%s"</comment> for LDAP name "%s" (strip-only match) → will rename to <info>"%s"</info>.',
                $stripOnly, $rawName, $currentNormalized
            ));
            $this->logger->info(sprintf(
                "GroupRenamer [auto-detect]: found legacy group '%s' for LDAP name '%s' → renaming to '%s'.",
                $stripOnly, $rawName, $currentNormalized
            ));
            return $stripOnly;
        }

        // Candidate 2: trimmed raw name
        $trimmed = trim($rawName);
        if (
            $trimmed !== ''
            && $trimmed !== $currentNormalized
            && $trimmed !== $stripOnly
            && $this->groupManager->groupExists($trimmed)
        ) {
            $this->writeln(sprintf(
                '  [group-rename] Auto-detected legacy group <comment>"%s"</comment> for LDAP name "%s" (raw name match) → will rename to <info>"%s"</info>.',
                $trimmed, $rawName, $currentNormalized
            ));
            $this->logger->info(sprintf(
                "GroupRenamer [auto-detect]: found legacy group '%s' (raw name) for LDAP name '%s' → renaming to '%s'.",
                $trimmed, $rawName, $currentNormalized
            ));
            return $trimmed;
        }

        return null;
    }


    // -------------------------------------------------------------------------
    // DB rename
    // -------------------------------------------------------------------------

    /**
     * Rename a single group inside a transaction.
     *
     * If the target GID already exists but has zero members it is treated as a
     * ghost group (e.g. created by a previous failed import) and deleted inside
     * the same transaction before the rename proceeds.
     *
     * Returns true on success, a warning string on a skippable conflict,
     * or false on DB error (already logged).
     *
     * @return true|false|string
     */
    private function renameOne(string $oldGid, string $newGid, string $source)
    {
        if (!$this->groupManager->groupExists($oldGid)) {
            $msg = sprintf("Cannot rename '%s' → '%s': source group does not exist.", $oldGid, $newGid);
            $this->writeln('<comment>  [group-rename] ' . $msg . '</comment>');
            $this->logger->warning("GroupRenamer [$source]: $msg");
            return $msg;
        }

        $deleteTargetFirst = false;

        if ($this->groupManager->groupExists($newGid)) {
            $targetGroup  = $this->groupManager->get($newGid);
            $memberCount  = ($targetGroup !== null) ? count($targetGroup->getUsers()) : -1;

            if ($memberCount === 0) {
                $deleteTargetFirst = true;
                $this->writeln(sprintf(
                    '  [group-rename] Target <comment>"%s"</comment> exists but has no members — will delete it and rename <comment>"%s"</comment>.',
                    $newGid, $oldGid
                ));
                $this->logger->info(sprintf(
                    "GroupRenamer [%s]: target '%s' is empty, will delete before renaming '%s'.",
                    $source, $newGid, $oldGid
                ));
            } else {
                $msg = sprintf(
                    "Cannot rename '%s' → '%s': target already exists and has %d member(s).",
                    $oldGid, $newGid, $memberCount
                );
                $this->writeln('<comment>  [group-rename] ' . $msg . '</comment>');
                $this->logger->warning("GroupRenamer [$source]: $msg");
                return $msg;
            }
        }

        $this->writeln(sprintf('  [group-rename] Renaming <comment>"%s"</comment> → <info>"%s"</info> …', $oldGid, $newGid));

        $this->db->beginTransaction();
        try {
            if ($deleteTargetFirst) {
                // Remove the empty target group from all core tables so the rename
                // can take its name. Any stale shares on the empty group are also
                // cleaned up — they carried no real access anyway.
                foreach (['groups', 'group_user', 'group_admin'] as $table) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->delete($table)
                        ->where($qb->expr()->eq('gid', $qb->createNamedParameter($newGid)))
                        ->executeStatement();
                }
                $qb = $this->db->getQueryBuilder();
                $qb->delete('share')
                    ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($newGid)))
                    ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->executeStatement();
            }

            // oc_groups: update both gid and displayname so the UI shows the correct name
            $qb = $this->db->getQueryBuilder();
            $qb->update('groups')
                ->set('gid', $qb->createNamedParameter($newGid))
                ->set('displayname', $qb->createNamedParameter($newGid))
                ->where($qb->expr()->eq('gid', $qb->createNamedParameter($oldGid)))
                ->executeStatement();

            foreach (['group_user' => 'gid', 'group_admin' => 'gid'] as $table => $col) {
                $qb = $this->db->getQueryBuilder();
                $qb->update($table)
                    ->set($col, $qb->createNamedParameter($newGid))
                    ->where($qb->expr()->eq($col, $qb->createNamedParameter($oldGid)))
                    ->executeStatement();
            }

            // Group shares: share_type = 1
            $qb = $this->db->getQueryBuilder();
            $qb->update('share')
                ->set('share_with', $qb->createNamedParameter($newGid))
                ->where($qb->expr()->eq('share_with', $qb->createNamedParameter($oldGid)))
                ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->db->commit();

            $detail = $deleteTargetFirst ? 'empty target deleted, ' : '';
            $this->writeln('  [group-rename] <info>Done</info> — ' . $detail . 'gid + displayname, memberships, shares updated.');
            $this->logger->info(sprintf(
                "GroupRenamer [%s]: renamed '%s' → '%s' (%sgid+displayname, group_user, group_admin, shares updated).",
                $source, $oldGid, $newGid, $detail
            ));
            $this->gidChanges[] = ['old' => $oldGid, 'new' => $newGid, 'source' => $source];
            return true;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->writeln(sprintf('  [group-rename] <error>DB error: %s</error>', $e->getMessage()));
            $this->logger->error(sprintf(
                "GroupRenamer [%s]: DB error renaming '%s' → '%s': %s",
                $source, $oldGid, $newGid, $e->getMessage()
            ));
            return false;
        }
    }


    // -------------------------------------------------------------------------
    // Normalization helpers
    // -------------------------------------------------------------------------

    private function normalizeGroupName(string $group): string
    {
        $group = trim($group);
        if ($group === '') {
            return '';
        }

        if (boolval($this->config->getAppValue(self::APP_NAME, 'cas_groups_letter_umlauts'))) {
            $group = str_replace("Ä", "Ae", $group);
            $group = str_replace("Ö", "Oe", $group);
            $group = str_replace("Ü", "Ue", $group);
            $group = str_replace("ä", "ae", $group);
            $group = str_replace("ö", "oe", $group);
            $group = str_replace("ü", "ue", $group);
            $group = str_replace("ß", "ss", $group);
        }

        return $this->applyCharFilter($group);
    }

    /**
     * Same char filter as current settings but NO umlaut substitution.
     * Reproduces the most common "old" normalization (umlauts were stripped).
     */
    private function normalizeGroupNameStripOnly(string $group): string
    {
        $group = trim($group);
        if ($group === '') {
            return '';
        }

        return $this->applyCharFilter($group);
    }

    private function applyCharFilter(string $group): string
    {
        $nameFilter = $this->config->getAppValue(self::APP_NAME, 'cas_groups_letter_filter');
        if (strlen($nameFilter) > 0) {
            $group = preg_replace("/[^" . $nameFilter . "]+/", "", $group);
        } else {
            $group = preg_replace("/[^a-zA-Z0-9\.\-_ @\/]+/", "", $group);
        }

        $group = trim($group);

        if (strlen($group) > 64) {
            $group = substr($group, 0, 63) . "…";
        }

        return $group;
    }


    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /** @return array<string, string> */
    private function loadDnMap(): array
    {
        $json = $this->config->getAppValue(self::APP_NAME, self::DN_MAP_KEY, '{}');
        $map  = json_decode($json, true);
        return is_array($map) ? $map : [];
    }

    /** @param array<string, string> $map */
    private function saveDnMap(array $map): void
    {
        $this->config->setAppValue(self::APP_NAME, self::DN_MAP_KEY, json_encode($map));
    }


    // -------------------------------------------------------------------------
    // Output helper
    // -------------------------------------------------------------------------

    private function writeln(string $message): void
    {
        if ($this->output !== null) {
            $this->output->writeln($message);
        }
    }
}
