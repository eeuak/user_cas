<?php


namespace OCA\UserCAS\Service\Merge;


use Psr\Log\LoggerInterface;

/**
 * Class AdUserMerger
 * @package OCA\UserCAS\Service\Merge
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp
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

            $this->logger->info("User " . $userToMerge["uid"] . " has to be merged …");

            // Compare users and select the account to use
            // Check if accounts are enabled or disabled
            //      if both disabled, account stays disabled
            //      if one is enabled, use the information of this one
            //      if both enabled, use information of $primaryAccountDnStartswWith

            if ($preferEnabledAccountsOverDisabled && $userStack[$userToMerge["uid"]]['enabled'] == 0 && $userToMerge['enabled'] == 1) {

                $this->logger->info("User " . $userToMerge["uid"] . " is merged because first account was disabled.");

                $userStack[$userToMerge["uid"]] = $userToMerge;
            } elseif ($userStack[$userToMerge["uid"]]['enabled'] == 1 && $userToMerge['enabled'] == 1) {

                if (strpos(strtolower($userToMerge['dn']), strtolower($primaryAccountDnStartswWith) !== FALSE)) {

                    $this->logger->info("User " . $userToMerge["uid"] . " is merged because second account is primary, based on DN filter.");

                    $userStack[$userToMerge["uid"]] = $userToMerge;
                }
                else {

                    $this->logger->info("User " . $userToMerge["uid"] . " has not been merged, second account was not primary, absed on DN filter.");
                }
            } else {

                $this->logger->info("User " . $userToMerge["uid"] . " has not been merged, second account was disabled, first account was enabled.");
            }
        } else { # User not in stack

            $userStack[$userToMerge["uid"]] = $userToMerge;
        }
    }
}