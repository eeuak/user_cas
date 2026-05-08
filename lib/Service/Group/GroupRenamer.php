<?php


namespace OCA\UserCAS\Service\Group;


use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;


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
    private const APP_NAME       = 'user_cas';
    private const DN_MAP_KEY     = 'cas_group_dn_gid_map';
    private const MANUAL_KEY     = 'cas_group_rename_pairs';

    /** @var IConfig */
    private $config;

    /** @var IGroupManager */
    private $groupManager;

    /** @var IDBConnection */
    private $db;

    /** @var LoggerInterface */
    private $logger;


    public function __construct(IConfig $config, IGroupManager $groupManager, IDBConnection $db, LoggerInterface $logger)
    {
        $this->config       = $config;
        $this->groupManager = $groupManager;
        $this->db           = $db;
        $this->logger       = $logger;
    }


    /**
     * @param array<string, string> $dnToRawName  dn => raw group name from LDAP
     * @return int  number of groups renamed
     */
    public function renameGroups(array $dnToRawName): int
    {
        $renamed  = 0;
        $renamed += $this->applyManualPairs();
        $renamed += $this->applyDnMapping($dnToRawName);
        return $renamed;
    }


    // -------------------------------------------------------------------------
    // Pass 1: manual pairs
    // -------------------------------------------------------------------------

    private function applyManualPairs(): int
    {
        $raw = trim((string) $this->config->getAppValue(self::APP_NAME, self::MANUAL_KEY, ''));
        if ($raw === '') {
            return 0;
        }

        $renamed = 0;
        foreach (preg_split("/[\r\n;]+/", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$oldGid, $newGid] = array_map('trim', explode(':', $line, 2));
            if ($oldGid !== '' && $newGid !== '' && $this->renameOne($oldGid, $newGid, 'manual')) {
                $renamed++;
            }
        }
        return $renamed;
    }


    // -------------------------------------------------------------------------
    // Pass 2: DN-based mapping with auto-detection for unmapped legacy groups
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $dnToRawName
     */
    private function applyDnMapping(array $dnToRawName): int
    {
        $storedMap  = $this->loadDnMap();
        $updatedMap = [];
        $renamed    = 0;

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
                    $ok = $this->renameOne($prevGid, $newGid, 'DN map');
                    $updatedMap[$dn] = $ok ? $newGid : $prevGid;
                    if ($ok) $renamed++;
                } elseif ($prevExists && $newExists) {
                    $this->logger->warning(sprintf(
                        "GroupRenamer [DN map]: cannot rename '%s' → '%s' (both exist); shares remain on '%s'.",
                        $prevGid, $newGid, $prevGid
                    ));
                    $updatedMap[$dn] = $prevGid;
                } else {
                    $updatedMap[$dn] = $newGid;
                }

            } elseif ($prevGid === null && !$newExists) {
                // Unmapped and target missing: check for a legacy group created under
                // different normalization settings (e.g. before umlauts were enabled).
                $candidate = $this->findLegacyCandidate($rawName, $newGid);
                if ($candidate !== null) {
                    $ok = $this->renameOne($candidate, $newGid, 'auto-detect');
                    $updatedMap[$dn] = $ok ? $newGid : $candidate;
                    if ($ok) $renamed++;
                } else {
                    // Group will be created fresh by the normal import loop
                    $updatedMap[$dn] = $newGid;
                }

            } else {
                $updatedMap[$dn] = $newGid;
            }
        }

        $this->saveDnMap($updatedMap);
        return $renamed;
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
            $this->logger->info(sprintf(
                "GroupRenamer [auto-detect]: found legacy group '%s' for LDAP name '%s' → will rename to '%s'.",
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
            $this->logger->info(sprintf(
                "GroupRenamer [auto-detect]: found legacy group '%s' (raw name) for LDAP name '%s' → will rename to '%s'.",
                $trimmed, $rawName, $currentNormalized
            ));
            return $trimmed;
        }

        return null;
    }


    // -------------------------------------------------------------------------
    // DB rename
    // -------------------------------------------------------------------------

    private function renameOne(string $oldGid, string $newGid, string $source): bool
    {
        if (!$this->groupManager->groupExists($oldGid)) {
            $this->logger->warning(sprintf(
                "GroupRenamer [%s]: cannot rename '%s' → '%s': source does not exist.",
                $source, $oldGid, $newGid
            ));
            return false;
        }

        if ($this->groupManager->groupExists($newGid)) {
            $this->logger->warning(sprintf(
                "GroupRenamer [%s]: cannot rename '%s' → '%s': target already exists.",
                $source, $oldGid, $newGid
            ));
            return false;
        }

        $this->db->beginTransaction();
        try {
            foreach (['groups' => 'gid', 'group_user' => 'gid', 'group_admin' => 'gid'] as $table => $col) {
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

            $this->logger->info(sprintf(
                "GroupRenamer [%s]: renamed '%s' → '%s' (groups, group_user, group_admin, shares updated).",
                $source, $oldGid, $newGid
            ));
            return true;

        } catch (\Throwable $e) {
            $this->db->rollBack();
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
}
