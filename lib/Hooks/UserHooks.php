<?php

/**
 * ownCloud - user_cas
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserCAS\Hooks;

/**
 * Class UserCAS_Hooks
 *
 * @package OCA\UserCAS\Hooks
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4.0
 */
class UserHooks
{

    /**
     * @var string
     */
    private $appName;

    /**
     * @var \OC\User\Manager $userManager
     */
    private $userManager;

    /**
     * @var \OC\User\Session $userSession
     */
    private $userSession;

    /**
     * @var \OCP\IConfig
     */
    private $config;

    /**
     * @var \OCA\UserCAS\Service\UserService $userService
     */
    private $userService;

    /**
     * @var \OCA\UserCAS\Service\AppService $appService
     */
    private $appService;


    /**
     * UserHooks constructor.
     *
     * @param string $appName
     * @param \OC\User\Manager $userManager
     * @param \OC\User\Session $userSession
     * @param \OCP\IConfig $config
     * @param \OCA\UserCAS\Service\UserService $userService
     * @param \OCA\UserCAS\Service\AppService $appService
     */
    public function __construct($appName, \OC\User\Manager $userManager, \OC\User\Session $userSession, \OCP\IConfig $config, \OCA\UserCAS\Service\UserService $userService, \OCA\UserCAS\Service\AppService $appService)
    {
        $this->appName = $appName;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->config = $config;
        $this->userService = $userService;
        $this->appService = $appService;

        if (!$this->appService->isCasInitialized()) $this->appService->init();
    }

    /**
     * Register method.
     */
    public function register()
    {
        $this->userSession->listen('\OC\User', 'postLogin', 'postLogin');
        $this->userSession->listen('\OC\User', 'logout', 'logout');
    }


    /**
     * postLogin method to update user data.
     *
     * @param \OC\User\User $user
     * @return bool
     */
    public function postLogin(\OC\User\User $user)
    {

        $uid = $user->getUID();

        if ($this->appService->isCasInitialized() && \phpCAS::isAuthenticated()) {

            \OCP\Util::writeLog('cas', 'phpCas post login hook triggered.' . $uid, \OCP\Util::DEBUG);

            // $cas_attributes may vary in name, therefore attributes are fetched to $attributes
            $casAttributes = \phpCAS::getAttributes();
            $casUid = \phpCAS::getUser();

            // parameters
            $attributes = array();


            if ($casUid === $uid) {

                \OCP\Util::writeLog('cas', 'attr  \"' . implode(',', $casAttributes) . '\" for the user: ' . $uid, \OCP\Util::DEBUG);


                $displayNameMapping = $this->config->getAppValue($this->appName, 'cas_displayName_mapping');
                if (array_key_exists($displayNameMapping, $casAttributes)) {

                    $attributes['cas_name'] = $casAttributes[$displayNameMapping];
                } else {

                    if (isset($casAttributes['cn'])) $attributes['cas_name'] = $casAttributes['cn'];
                }

                $mailMapping = $this->config->getAppValue($this->appName, 'cas_email_mapping');
                if (array_key_exists($mailMapping, $casAttributes)) {

                    $attributes['cas_email'] = $casAttributes[$mailMapping];
                } else {

                    if (isset($casAttributes['mail'])) $attributes['cas_email'] = $casAttributes['mail'];
                }


                // Group handling
                $groupMapping = $this->config->getAppValue($this->appName, 'cas_group_mapping');
                $defaultGroup = $this->config->getAppValue($this->appName, 'cas_default_group');
                if (array_key_exists($groupMapping, $casAttributes)) {

                    $attributes['cas_groups'] = $casAttributes[$groupMapping];
                } else if (is_string($defaultGroup) && strlen($defaultGroup) > 0) {

                    $attributes['cas_groups'] = array($defaultGroup);
                    \OCP\Util::writeLog('cas', 'Using default group "' . $defaultGroup . '" for the user: ' . $uid, \OCP\Util::DEBUG);
                }

                // Autocreate user if needed
                if (!$this->userService->userExists($uid) && $this->config->getAppValue($this->appName, 'cas_autocreate')) {

                    // create users if they do not exist
                    if (preg_match('/[^a-zA-Z0-9 _\.@\-]/', $uid)) {

                        \OCP\Util::writeLog('cas', 'Invalid username "' . $uid . '", allowed chars "a-zA-Z0-9" and "_.@-" ', \OCP\Util::DEBUG);

                        return FALSE;
                    } else {

                        \OCP\Util::writeLog('cas', 'phpCAS creates a new user with UID: ' . $uid, \OCP\Util::DEBUG);

                        /** @var bool|\OCP\IUser the created user or false $uid */
                        $user = $this->userService->create($uid);

                        if ($user instanceof \OCP\IUser) {

                            \OCP\Util::writeLog('cas', 'phpCAS created new user with UID: ' . $uid, \OCP\Util::DEBUG);
                        }
                    }
                }

                // Try to update user attributes
                if ($this->config->getAppValue($this->appName, 'cas_update_user_data')) {

                    $this->userService->updateUser($user, $attributes);
                }

                return TRUE;
            }
        } else {

            \OCP\Util::writeLog('cas', 'phpCas post login hook NOT triggered.' . $uid, \OCP\Util::DEBUG);
        }

        return FALSE;
    }

    /**
     * Logout hook method.
     */
    public function logout()
    {

        \OCP\Util::writeLog('cas', 'Logout hook triggered.', \OCP\Util::DEBUG);

        if ($this->config->getAppValue($this->appName, 'cas_disable_logout') === 'off' && \phpCAS::isAuthenticated()) {

            \phpCAS::logout();

            \OCP\Util::writeLog('cas', 'phpCAS logging our.', \OCP\Util::DEBUG);
        }
        else {
            \OCP\Util::writeLog('cas', 'phpCAS not logging out.', \OCP\Util::DEBUG);
        }
    }
}