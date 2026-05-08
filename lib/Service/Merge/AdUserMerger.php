<?php


namespace OCA\UserCAS\Service\Merge;


use Psr\Log\LoggerInterface;

/**
 * Class AdUserMerger
 * @package OCA\UserCAS\Service\Merge
 *
 * @author Original contributors
 * @copyright Original contributors
 *
 * @since 1.0.0
 */
class AdUserMerger implements MergerInterface
{


    /**
     * @var LoggerInterface
     */
    protected $logger;


    /**
     * AdUserMerger constructor.
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Merge users method
     *
     * @param array $userStack
     * @param array $userToMerge
     * @param bool $merge
     * @param bool $preferEnabledAccountsOverDisabled
     * @param string $primaryAccountDnStartswWith
     */
    public function mergeUsers(array &$userStack, array $userToMerge, $merge, $preferEnabledAccountsOverDisabled, $primaryAccountDnStartswWith)
    {
        # User already in stack
        if ($merge && isset($userStack[$userToMerge["uid"]])) {

            $this->logger->debug("User " . $userToMerge["uid"] . " has to be merged …");

            // Check if accounts are enabled or disabled
            //      if both disabled, first account stays
            //      if one is enabled, use this account
            //      if both enabled, use information of $primaryAccountDnStartswWith

            if ($preferEnabledAccountsOverDisabled && $userStack[$userToMerge["uid"]]['enable'] == 0 && $userToMerge['enable'] == 1) { # First disabled, second enabled and $preferEnabledAccountsOverDisabled is true

                $this->logger->info("User " . $userToMerge["uid"] . " is merged because first account was disabled.");

                $mergedGroups = $this->mergeGroupLists($userStack[$userToMerge["uid"]]['groups'], $userToMerge['groups']);
                $userStack[$userToMerge["uid"]] = $userToMerge;
                $userStack[$userToMerge["uid"]]['groups'] = $mergedGroups;

            } elseif (!$preferEnabledAccountsOverDisabled && $userStack[$userToMerge["uid"]]['enable'] == 0 && $userToMerge['enable'] == 1) { # First disabled, second enabled and $preferEnabledAccountsOverDisabled is false

                $this->logger->info("User " . $userToMerge["uid"] . " has not been merged, second enabled account was not preferred, because of preferEnabledAccountsOverDisabled option.");
                $userStack[$userToMerge["uid"]]['groups'] = $this->mergeGroupLists($userStack[$userToMerge["uid"]]['groups'], $userToMerge['groups']);

            } elseif ($userStack[$userToMerge["uid"]]['enable'] == 1 && $userToMerge['enable'] == 1) { # Both enabled

                $this->logger->info("Mergeparams " . $userToMerge["dn"] . "-      -" . $primaryAccountDnStartswWith);

                # Bug fix: strpos(x, '') === 0, not false — guard against unconfigured filter matching everything
                if ($primaryAccountDnStartswWith !== '' && strpos(strtolower($userToMerge['dn']), strtolower($primaryAccountDnStartswWith)) !== false) {

                    $this->logger->info("User " . $userToMerge["uid"] . " is merged because second account is primary, based on merge filter.");

                    $mergedGroups = $this->mergeGroupLists($userStack[$userToMerge["uid"]]['groups'], $userToMerge['groups']);
                    $userStack[$userToMerge["uid"]] = $userToMerge;
                    $userStack[$userToMerge["uid"]]['groups'] = $mergedGroups;

                } else {

                    $this->logger->info("User " . $userToMerge["uid"] . " has not been merged, second account was not primary, based on merge filter.");
                    $userStack[$userToMerge["uid"]]['groups'] = $this->mergeGroupLists($userStack[$userToMerge["uid"]]['groups'], $userToMerge['groups']);
                }

            } else {

                $this->logger->info("User " . $userToMerge["uid"] . " has not been merged, second account was disabled, first account was enabled.");
            }

        } elseif (isset($userStack[$userToMerge["uid"]])) { # User in stack but merge disabled — skip duplicate, log warning

            $this->logger->warning("User " . $userToMerge["uid"] . " appears multiple times in LDAP but merge is disabled. Keeping first entry, skipping duplicate.");

        } else { # User not in stack

            $userStack[$userToMerge["uid"]] = $userToMerge;
        }
    }

    /**
     * @param array<int, string> $groupsA
     * @param array<int, string> $groupsB
     * @return array<int, string>
     */
    private function mergeGroupLists(array $groupsA, array $groupsB): array
    {
        return array_values(array_unique(array_merge($groupsA, $groupsB)));
    }
}
