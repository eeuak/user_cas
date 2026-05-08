<?php


namespace OCA\UserCAS\Command;

use OC\User\Manager;
use OCA\UserCAS\Service\Group\GroupRenamer;
use OCA\UserCAS\Service\Import\AdImporter;
use OCA\UserCAS\Service\Import\ImporterInterface;
use OCP\IConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * Class ImportUsersAd
 * @package OCA\UserCAS\Command
 *
 * @author Original contributors
 * @copyright Original contributors
 *
 * @since 1.0.0
 */
class ImportUsersAd extends Command
{
    private const IMPORT_STATS_PREFIX = 'IMPORT_STATS ';


    /**
     * @var Manager $userManager
     */
    private $userManager;

    /**
     * @var IConfig
     */
    private $config;

    /**
     * @var IGroupManager
     */
    private $groupManager;


    /**
     * ImportUsersAd constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->userManager = \OC::$server->getUserManager();
        $this->groupManager = \OC::$server->getGroupManager();
        $this->config = \OC::$server->getConfig();
    }

    /**
     * Configure method
     */
    protected function configure()
    {
        $this
            ->setName('cas:import-users-ad')
            ->setDescription('Imports users from an ActiveDirectory LDAP.')
            ->addOption(
                'delta-update',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Activate updates on existing accounts'
            )
            ->addOption(
                'convert-backend',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Convert the backend to CAS (on update only)'
            );
    }

    /**
     * Execute method
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
protected function execute(InputInterface $input, OutputInterface $output)
{
    try {
        $logger = new ConsoleLogger($output);

        if (!extension_loaded("ldap")) {
            throw new \Exception("User import failed. PHP extension 'ldap' is not loaded.");
        }

        $output->writeln('Start account import from ActiveDirectory.');

        $importer = new AdImporter($this->config);
        $importer->init($logger);

        $allUsers = $importer->getUsers();

        $groupDns = $importer->getResolvedGroupDns();
        if (!empty($groupDns)) {
            $renamer = new GroupRenamer(
                $this->config,
                $this->groupManager,
                \OC::$server->getDatabaseConnection(),
                $logger
            );
            $renamedCount = $renamer->renameGroups($groupDns);
            if ($renamedCount > 0) {
                $output->writeln(sprintf('<info>Renamed %d group(s) to match current normalization settings.</info>', $renamedCount));
            }
        }

        $output->writeln('Account import from ActiveDirectory finished.');
        $output->writeln('Start account import to database.');

        $progressBar = new ProgressBar($output, count($allUsers));

        $convertBackend = $input->getOption('convert-backend');
        $deltaUpdate = $input->getOption('delta-update');

        if ($convertBackend) {
            $logger->info("Backend conversion: Backends will be converted to CAS-Backend.");
        }
        if ($deltaUpdate) {
            $logger->info("Delta updates: Existing users will be updated.");
        }

        $createCommand = $this->getApplication()->find('cas:create-user');
        $updateCommand = $this->getApplication()->find('cas:update-user');

        $failedUsers = [];
        $emptyExcludedGroups = [];
        $stats = [
            'processed' => 0,
            'added' => 0,
            'updated' => 0,
            'users_deactivated' => 0,
            'deactivation_candidates' => 0,
            'skipped_existing' => 0,
            'failed' => 0,
            'groups_created' => 0,
            'group_memberships_added' => 0,
            'group_memberships_removed' => 0,
            'empty_excluded_groups' => 0,
        ];
        $processedUserIds = [];

        foreach ($allUsers as $user) {
            $stats['processed']++;
            $processedUserIds[(string)$user["uid"]] = true;
            $arguments = [
                'command' => 'cas:create-user',
                'uid' => $user["uid"],
                '--display-name' => $user["displayName"],
                '--email' => $user["email"],
                '--quota' => $user["quota"],
                '--enabled' => $user["enable"],
                '--group' => $user["groups"]
            ];

            $logger->info(sprintf(
                'Processing user uid="%s", displayName="%s"',
                (string) $user["uid"],
                (string) $user["displayName"]
            ));

            try {
                if (!$this->userManager->userExists($user["uid"])) {
                    $input = new ArrayInput($arguments);
                    $bufferedOutput = new BufferedOutput();
                    $exitCode = $createCommand->run($input, $bufferedOutput);
                    $commandOutput = trim($bufferedOutput->fetch());
                    if ($exitCode !== 0) {
                        throw new \RuntimeException(
                            sprintf(
                                'Create command failed for uid="%s" (exit %d). Output: %s',
                                (string) $user["uid"],
                                $exitCode,
                                $commandOutput
                            )
                        );
                    }
                    $commandStats = $this->extractImportStats($commandOutput);
                    $this->mergeStats($stats, $commandStats);
                    if (($commandStats['users_deactivated'] ?? 0) > 0) {
                        $logger->info(sprintf(
                            'Deactivated user uid="%s", displayName="%s"',
                            (string)$user["uid"],
                            (string)$user["displayName"]
                        ));
                    }
                } else if ($deltaUpdate) {
                    $arguments['command'] = 'cas:update-user';
                    if ($convertBackend) {
                        $arguments["--convert-backend"] = 1;
                    }
                    $input = new ArrayInput($arguments);
                    $bufferedOutput = new BufferedOutput();
                    $exitCode = $updateCommand->run($input, $bufferedOutput);
                    $commandOutput = trim($bufferedOutput->fetch());
                    if ($exitCode !== 0) {
                        throw new \RuntimeException(
                            sprintf(
                                'Update command failed for uid="%s" (exit %d). Output: %s',
                                (string) $user["uid"],
                                $exitCode,
                                $commandOutput
                            )
                        );
                    }
                    $commandStats = $this->extractImportStats($commandOutput);
                    $this->mergeStats($stats, $commandStats);
                    if (($commandStats['users_deactivated'] ?? 0) > 0) {
                        $logger->info(sprintf(
                            'Deactivated user uid="%s", displayName="%s"',
                            (string)$user["uid"],
                            (string)$user["displayName"]
                        ));
                    }
                } else {
                    $stats['skipped_existing']++;
                }
            } catch (\Throwable $e) {
                $logger->critical(sprintf(
                    'Import failed for uid="%s", displayName="%s". Error: %s',
                    (string) $user["uid"],
                    (string) $user["displayName"],
                    $e->getMessage()
                ));
                $failedUsers[] = [
                    'uid' => (string) $user["uid"],
                    'displayName' => (string) $user["displayName"],
                    'error' => $e->getMessage(),
                ];
                $stats['failed']++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $progressBar->clear();

        if ($deltaUpdate) {
            foreach ($importer->getDeactivatedUserIds() as $uid) {
                $stats['deactivation_candidates']++;

                if (isset($processedUserIds[$uid]) || !$this->userManager->userExists($uid)) {
                    continue;
                }

                try {
                    $existingUser = $this->userManager->get($uid);
                    if ($existingUser === null || !$existingUser->isEnabled()) {
                        continue;
                    }

                    $input = new ArrayInput([
                        'command' => 'cas:update-user',
                        'uid' => $uid,
                        '--enabled' => 0,
                    ]);
                    $bufferedOutput = new BufferedOutput();
                    $exitCode = $updateCommand->run($input, $bufferedOutput);
                    $commandOutput = trim($bufferedOutput->fetch());

                    if ($exitCode !== 0) {
                        throw new \RuntimeException(
                            sprintf(
                                'Deactivation update failed for uid="%s" (exit %d). Output: %s',
                                $uid,
                                $exitCode,
                                $commandOutput
                            )
                        );
                    }

                    $commandStats = $this->extractImportStats($commandOutput);
                    $this->mergeStats($stats, $commandStats);
                    if (($commandStats['users_deactivated'] ?? 0) > 0) {
                        $logger->info(sprintf(
                            'Deactivated user uid="%s", displayName="%s"',
                            $uid,
                            (string)$existingUser->getDisplayName()
                        ));
                    }
                } catch (\Throwable $e) {
                    $logger->critical(sprintf(
                        'Deactivation failed for uid="%s". Error: %s',
                        $uid,
                        $e->getMessage()
                    ));
                    $failedUsers[] = [
                        'uid' => $uid,
                        'displayName' => '',
                        'error' => $e->getMessage(),
                    ];
                    $stats['failed']++;
                }
            }
        }

        foreach ($importer->getExcludedGroups() as $excludedGroup) {
            $groupName = $this->normalizeGroupName((string)$excludedGroup['name']);
            if ($groupName === '' || !$this->groupManager->groupExists($groupName)) {
                continue;
            }

            $group = $this->groupManager->get($groupName);
            if ($group === null) {
                continue;
            }

            if (count($group->getUsers()) === 0) {
                $emptyExcludedGroups[$groupName] = $excludedGroup;
            }
        }

        ksort($emptyExcludedGroups);
        $stats['empty_excluded_groups'] = count($emptyExcludedGroups);

        $output->writeln('Account import to database finished.');
        $output->writeln(sprintf(
            'Import statistics: processed=%d added=%d updated=%d users_deactivated=%d deactivation_candidates=%d skipped_existing=%d failed=%d groups_created=%d group_memberships_added=%d group_memberships_removed=%d empty_excluded_groups=%d',
            $stats['processed'],
            $stats['added'],
            $stats['updated'],
            $stats['users_deactivated'],
            $stats['deactivation_candidates'],
            $stats['skipped_existing'],
            $stats['failed'],
            $stats['groups_created'],
            $stats['group_memberships_added'],
            $stats['group_memberships_removed'],
            $stats['empty_excluded_groups']
        ));

        if (count($failedUsers) > 0) {
            $output->writeln(sprintf(
                '<comment>Import completed with %d skipped user(s):</comment>',
                count($failedUsers)
            ));
            foreach ($failedUsers as $failedUser) {
                $output->writeln(sprintf(
                    '<comment>- uid="%s", displayName="%s", error="%s"</comment>',
                    $failedUser['uid'],
                    $failedUser['displayName'],
                    $failedUser['error']
                ));
            }
        }

        if (count($emptyExcludedGroups) > 0) {
            $output->writeln(sprintf(
                '<comment>Excluded groups with no members left in Nextcloud: %d</comment>',
                count($emptyExcludedGroups)
            ));
            foreach ($emptyExcludedGroups as $emptyExcludedGroup) {
                $output->writeln(sprintf(
                    '<comment>- group="%s", dn="%s"</comment>',
                    $emptyExcludedGroup['name'],
                    $emptyExcludedGroup['dn']
                ));
            }
        }

        $importer->close();

        return 0; // Successfully completed
    } catch (\Exception $e) {
        $logger->critical("Fatal Error: " . $e->getMessage());
        return 1; // Error occurred
    }
    }

    /**
     * @param string $commandOutput
     * @return array<string, int>
     */
    private function extractImportStats($commandOutput)
    {
        $stats = [];
        $lines = preg_split("/\r\n|\n|\r/", (string)$commandOutput);

        foreach ($lines as $line) {
            if (strpos($line, self::IMPORT_STATS_PREFIX) !== 0) {
                continue;
            }

            $pairs = explode(' ', substr($line, strlen(self::IMPORT_STATS_PREFIX)));
            foreach ($pairs as $pair) {
                if ($pair === '' || strpos($pair, '=') === false) {
                    continue;
                }

                list($key, $value) = explode('=', $pair, 2);
                $stats[$key] = intval($value);
            }
        }

        return $stats;
    }

    /**
     * @param array<string, int> $totals
     * @param array<string, int> $stats
     */
    private function mergeStats(array &$totals, array $stats)
    {
        foreach ($stats as $key => $value) {
            if (!array_key_exists($key, $totals)) {
                $totals[$key] = 0;
            }

            $totals[$key] += $value;
        }
    }

    /**
     * Apply the same group normalization used during group sync.
     *
     * @param string $group
     * @return string
     */
    private function normalizeGroupName($group)
    {
        $group = trim((string)$group);

        if ($group === '') {
            return '';
        }

        if (boolval($this->config->getAppValue('user_cas', 'cas_groups_letter_umlauts'))) {
            $group = str_replace("Ä", "Ae", $group);
            $group = str_replace("Ö", "Oe", $group);
            $group = str_replace("Ü", "Ue", $group);
            $group = str_replace("ä", "ae", $group);
            $group = str_replace("ö", "oe", $group);
            $group = str_replace("ü", "ue", $group);
            $group = str_replace("ß", "ss", $group);
        }

        $nameFilter = $this->config->getAppValue('user_cas', 'cas_groups_letter_filter');

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
}
