<?php


namespace OCA\UserCAS\Command;


use OCA\UserCAS\Service\Import\AdImporter;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Dry-run import of a single LDAP user.
 *
 * Shows the full import decision chain — LDAP data, group normalization,
 * GroupRenamer logic, and the final add/remove diff against the user's
 * current Nextcloud groups — without writing anything to the database.
 */
class DebugImportUserAd extends Command
{
    private const APP_NAME   = 'user_cas';
    private const DN_MAP_KEY = 'cas_group_dn_gid_map';

    /** @var IConfig */
    private $config;

    /** @var IGroupManager */
    private $groupManager;

    /** @var IUserManager */
    private $userManager;


    public function __construct()
    {
        parent::__construct();

        $this->config       = \OC::$server->getConfig();
        $this->groupManager = \OC::$server->getGroupManager();
        $this->userManager  = \OC::$server->getUserManager();
    }


    protected function configure()
    {
        $this
            ->setName('cas:debug-import-user-ad')
            ->setDescription('Dry-run import of one LDAP user — shows every group decision without making changes.')
            ->addArgument(
                'identifier',
                InputArgument::REQUIRED,
                'Value to look up (employee ID, sAMAccountName, etc.).'
            )
            ->addOption(
                'attribute',
                'a',
                InputOption::VALUE_OPTIONAL,
                'LDAP attribute to search with. Defaults to the configured UID mapping attribute.'
            );
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!extension_loaded('ldap')) {
            $output->writeln('<error>PHP extension "ldap" is not loaded.</error>');
            return 1;
        }

        $identifier = (string) $input->getArgument('identifier');
        $attribute  = (string) $input->getOption('attribute');
        $logger     = new ConsoleLogger($output);
        $importer   = new AdImporter($this->config);

        try {
            $importer->init($logger);
            $matches = $importer->debugUser($identifier, $attribute);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        } finally {
            try { $importer->close(); } catch (\Throwable $e) {}
        }

        if (count($matches) === 0) {
            $output->writeln(sprintf(
                '<comment>No LDAP entries found for "%s"%s.</comment>',
                $identifier,
                $attribute !== '' ? sprintf(' (attribute: %s)', $attribute) : ''
            ));
            return 0;
        }

        $umloutsEnabled  = boolval($this->config->getAppValue(self::APP_NAME, 'cas_groups_letter_umlauts'));
        $letterFilter    = $this->config->getAppValue(self::APP_NAME, 'cas_groups_letter_filter');
        $storedDnMap     = $this->loadDnMap();

        foreach ($matches as $idx => $match) {
            $this->analyseMatch($output, $idx, $match, $umloutsEnabled, $letterFilter, $storedDnMap);
        }

        return 0;
    }


    // -------------------------------------------------------------------------

    private function analyseMatch(
        OutputInterface $output,
        int $idx,
        array $match,
        bool $umloutsEnabled,
        string $letterFilter,
        array $storedDnMap
    ): void {
        $user = $match['mapped_user'];
        $uid  = (string) $user['uid'];

        $output->writeln('');
        $output->writeln(sprintf('<info>══ LDAP match %d ══</info>', $idx + 1));

        // --- User summary ---
        $output->writeln(sprintf('  UID:            %s',
            $uid !== '' ? $uid : '<error>(empty — would be skipped)</error>'
        ));
        $output->writeln(sprintf('  Display name:   %s', $user['displayName']));
        $output->writeln(sprintf('  Email:          %s', $user['email']));
        $output->writeln(sprintf('  Enabled:        %s', $user['enable'] ? 'yes' : '<comment>no</comment>'));
        $output->writeln(sprintf('  Matches sync filter:         %s', $match['matches_sync_filter']         ? 'yes' : '<comment>no</comment>'));
        $output->writeln(sprintf('  Matches deactivation filter: %s', $match['matches_deactivation_filter'] ? '<comment>yes</comment>' : 'no'));
        $output->writeln(sprintf('  Would be imported: %s', $match['would_be_imported'] ? '<info>yes</info>' : '<comment>no</comment>'));

        $ncUserExists = $uid !== '' && $this->userManager->userExists($uid);
        $output->writeln(sprintf('  Already in Nextcloud: %s',
            $ncUserExists ? '<info>yes</info>' : 'no (would be created)'
        ));

        // --- Settings in effect ---
        $output->writeln('');
        $output->writeln('  <info>── Normalization settings ──</info>');
        $output->writeln(sprintf('  Umlaut replacement (cas_groups_letter_umlauts): %s',
            $umloutsEnabled ? '<info>ON</info>' : '<comment>OFF — group rename step is skipped entirely</comment>'
        ));
        $output->writeln(sprintf('  Letter filter (cas_groups_letter_filter): %s',
            $letterFilter !== '' ? $letterFilter : '(default: a-zA-Z0-9.\\-_ @/)'
        ));

        // --- Excluded groups ---
        if (count($match['excluded_groups']) > 0) {
            $output->writeln('');
            $output->writeln(sprintf('  <comment>── Excluded groups (%d) ──</comment>', count($match['excluded_groups'])));
            foreach ($match['excluded_groups'] as $eg) {
                $output->writeln(sprintf('  <comment>  - "%s"  [%s]</comment>', $eg['name'], $eg['dn']));
            }
        }

        // --- Group rename dry-run ---
        $resolvedGroups = $match['resolved_groups'];
        $output->writeln('');
        $output->writeln(sprintf('  <info>── Group rename dry-run (%d group(s)) ──</info>', count($resolvedGroups)));

        if (!$umloutsEnabled) {
            $output->writeln('  <comment>  (skipped — umlaut replacement is OFF)</comment>');
        }

        // finalGroupNames[dn] = gid that would exist after rename dry-run
        $finalGroupNames = [];

        foreach ($resolvedGroups as $i => $rg) {
            $dn      = (string) $rg['dn'];
            $rawName = (string) $rg['name'];

            $output->writeln('');
            $output->writeln(sprintf('  [%d/%d] raw: <comment>"%s"</comment>', $i + 1, count($resolvedGroups), $rawName));
            $output->writeln(sprintf('         DN:  %s', $dn));

            $normalized = $this->normalizeGroupName($rawName, $umloutsEnabled, $letterFilter);
            $stripOnly  = $this->normalizeGroupName($rawName, false, $letterFilter);

            $output->writeln(sprintf('         Normalized (current settings): <info>"%s"</info>', $normalized));

            if ($normalized !== $stripOnly) {
                $output->writeln(sprintf('         Strip-only (no umlauts):       <comment>"%s"</comment>', $stripOnly));
            } else {
                $output->writeln('         Strip-only (no umlauts):       (same as normalized)');
            }

            $prevGid = $storedDnMap[$dn] ?? null;
            $output->writeln(sprintf('         DN in stored map: %s',
                $prevGid !== null ? sprintf('<info>"%s"</info>', $prevGid) : '(not yet stored)'
            ));

            if (!$umloutsEnabled) {
                $finalGroupNames[$dn] = $normalized;
                $output->writeln('         → <comment>Rename step skipped (umlauts OFF)</comment>');
                continue;
            }

            // Simulate GroupRenamer decision
            $this->simulateRenameDecision($output, $dn, $rawName, $normalized, $stripOnly, $prevGid, $finalGroupNames);
        }

        // --- updateGroups diff ---
        $output->writeln('');
        $output->writeln('  <info>── updateGroups diff ──</info>');

        $targetGids = array_values(array_filter(array_unique(array_values($finalGroupNames))));

        if ($ncUserExists) {
            $currentGroups = array_map(
                function ($g) { return $g->getGID(); },
                $this->groupManager->getUserGroups($this->userManager->get($uid))
            );
            $protectedRaw   = $this->config->getAppValue(self::APP_NAME, 'cas_protected_groups', '');
            $protectedGroups = array_filter(array_map('trim', explode(',', $protectedRaw)));

            $toAdd    = array_diff($targetGids, $currentGroups);
            $toRemove = array_filter(
                array_diff($currentGroups, $targetGids),
                function ($g) use ($protectedGroups) { return !in_array($g, $protectedGroups, true); }
            );

            $output->writeln(sprintf('  Current NC groups (%d):', count($currentGroups)));
            foreach ($currentGroups as $g) {
                $output->writeln(sprintf('    - "%s"', $g));
            }
            $output->writeln(sprintf('  Target groups after rename (%d):', count($targetGids)));
            foreach ($targetGids as $g) {
                $output->writeln(sprintf('    - "%s"', $g));
            }
            $output->writeln(count($toAdd) > 0
                ? sprintf('  <info>+ Would add (%d):</info>', count($toAdd))
                : '  + Would add: (none)');
            foreach ($toAdd as $g) {
                $ncExists = $this->groupManager->groupExists($g);
                $output->writeln(sprintf('      <info>+ "%s"%s</info>', $g, $ncExists ? '' : ' (group would be created)'));
            }
            $output->writeln(count($toRemove) > 0
                ? sprintf('  <comment>- Would remove (%d):</comment>', count($toRemove))
                : '  - Would remove: (none)');
            foreach ($toRemove as $g) {
                $output->writeln(sprintf('      <comment>- "%s"</comment>', $g));
            }
            if (count($protectedGroups) > 0) {
                $output->writeln(sprintf('  Protected (never removed): %s', implode(', ', $protectedGroups)));
            }
        } else {
            $output->writeln('  User not in Nextcloud yet — all groups would be assigned fresh:');
            foreach ($targetGids as $g) {
                $ncExists = $this->groupManager->groupExists($g);
                $output->writeln(sprintf('    + "%s"%s', $g, $ncExists ? '' : ' (group would be created)'));
            }
        }
    }


    // -------------------------------------------------------------------------

    private function simulateRenameDecision(
        OutputInterface $output,
        string $dn,
        string $rawName,
        string $normalized,
        string $stripOnly,
        ?string $prevGid,
        array &$finalGroupNames
    ): void {
        $ncNormalized = $this->groupInfo($normalized);
        $ncStripOnly  = $normalized !== $stripOnly ? $this->groupInfo($stripOnly) : null;

        $output->writeln(sprintf('         NC "%s": %s', $normalized, $this->formatGroupInfo($ncNormalized)));
        if ($ncStripOnly !== null) {
            $output->writeln(sprintf('         NC "%s": %s', $stripOnly, $this->formatGroupInfo($ncStripOnly)));
        }

        if ($prevGid !== null && $prevGid !== $normalized) {
            // Known rename from stored map
            $ncPrev = $this->groupInfo($prevGid);
            $output->writeln(sprintf('         NC "%s" (from map): %s', $prevGid, $this->formatGroupInfo($ncPrev)));

            if ($ncPrev !== null && $ncNormalized === null) {
                $output->writeln(sprintf('         → <info>WOULD RENAME</info> "%s" → "%s" (detected via DN map)', $prevGid, $normalized));
                $finalGroupNames[$dn] = $normalized;
            } elseif ($ncPrev !== null && $ncNormalized !== null && $ncNormalized['members'] === 0) {
                $output->writeln(sprintf('         → <info>WOULD DELETE phantom "%s" + RENAME "%s" → "%s"</info>', $normalized, $prevGid, $normalized));
                $finalGroupNames[$dn] = $normalized;
            } elseif ($ncPrev !== null && $ncNormalized !== null) {
                $output->writeln(sprintf('         → <comment>SKIP — both exist and target has members; shares stay on "%s"</comment>', $prevGid));
                $finalGroupNames[$dn] = $prevGid;
            } else {
                $output->writeln(sprintf('         → <comment>Source "%s" missing; using "%s" as-is</comment>', $prevGid, $normalized));
                $finalGroupNames[$dn] = $normalized;
            }
            return;
        }

        // Not in map yet — check for auto-detect scenarios
        if ($ncNormalized === null) {
            // Target missing — look for legacy candidate
            if ($ncStripOnly !== null) {
                $output->writeln(sprintf(
                    '         → <info>WOULD AUTO-DETECT + RENAME "%s" → "%s"</info>',
                    $stripOnly, $normalized
                ));
                $finalGroupNames[$dn] = $normalized;
            } else {
                $output->writeln(sprintf('         → <comment>Neither version found in NC — would CREATE "%s" fresh</comment>', $normalized));
                $finalGroupNames[$dn] = $normalized;
            }
        } else {
            // Target exists — check if it is empty (phantom)
            if ($ncNormalized['members'] === 0) {
                if ($ncStripOnly !== null && $ncStripOnly['members'] > 0) {
                    $output->writeln(sprintf(
                        '         → <info>WOULD DELETE phantom "%s" (0 members) + RENAME "%s" → "%s"</info>',
                        $normalized, $stripOnly, $normalized
                    ));
                    $finalGroupNames[$dn] = $normalized;
                } elseif ($ncStripOnly !== null && $ncStripOnly['members'] === 0) {
                    $output->writeln(sprintf('         → <comment>Both "%s" and "%s" exist but both empty — no rename, group kept as "%s"</comment>', $normalized, $stripOnly, $normalized));
                    $finalGroupNames[$dn] = $normalized;
                } else {
                    $output->writeln(sprintf('         → <comment>"%s" exists (empty) but no legacy candidate found — kept as-is</comment>', $normalized));
                    $finalGroupNames[$dn] = $normalized;
                }
            } else {
                $output->writeln(sprintf('         → <info>OK</info> — "%s" exists with %d member(s), nothing to rename', $normalized, $ncNormalized['members']));
                $finalGroupNames[$dn] = $normalized;
            }
        }
    }


    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns ['members' => int] if the group exists, null if it does not.
     * @return array{members: int}|null
     */
    private function groupInfo(string $gid): ?array
    {
        if (!$this->groupManager->groupExists($gid)) {
            return null;
        }
        $group = $this->groupManager->get($gid);
        return ['members' => $group !== null ? count($group->getUsers()) : 0];
    }

    /** @param array{members: int}|null $info */
    private function formatGroupInfo(?array $info): string
    {
        if ($info === null) {
            return 'not found in Nextcloud';
        }
        if ($info['members'] === 0) {
            return '<comment>EXISTS — 0 members (phantom)</comment>';
        }
        return sprintf('<info>EXISTS — %d member(s)</info>', $info['members']);
    }

    private function normalizeGroupName(string $group, bool $umlauts, string $letterFilter): string
    {
        $group = trim($group);
        if ($group === '') {
            return '';
        }

        if ($umlauts) {
            $group = str_replace("Ä", "Ae", $group);
            $group = str_replace("Ö", "Oe", $group);
            $group = str_replace("Ü", "Ue", $group);
            $group = str_replace("ä", "ae", $group);
            $group = str_replace("ö", "oe", $group);
            $group = str_replace("ü", "ue", $group);
            $group = str_replace("ß", "ss", $group);
        }

        if (strlen($letterFilter) > 0) {
            $group = preg_replace("/[^" . $letterFilter . "]+/", "", $group);
        } else {
            $group = preg_replace("/[^a-zA-Z0-9\.\-_ @\/]+/", "", $group);
        }

        $group = trim($group);

        if (strlen($group) > 64) {
            $group = substr($group, 0, 63) . "…";
        }

        return $group;
    }

    /** @return array<string, string> */
    private function loadDnMap(): array
    {
        $json = $this->config->getAppValue(self::APP_NAME, self::DN_MAP_KEY, '{}');
        $map  = json_decode($json, true);
        return is_array($map) ? $map : [];
    }
}
