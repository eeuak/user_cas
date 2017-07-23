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

namespace OCA\UserCAS\Service;

use OCA\UserCAS\User\Backend;
use \OCP\IConfig;
use \OCP\IUserManager;
use \OCP\IGroupManager;
use \OCP\IUserSession;

/**
 * Class UserService
 *
 * @package OCA\UserCAS\Service
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4
 */
class UserService
{

    /**
     * @var string $appName
     */
    private $appName;

    /**
     * @var \OCP\IConfig $appConfig
     */
    private $config;

    /**
     * @var \OCP\IUserSession; $userSession
     */
    private $userSession;

    /**
     * @var \OCP\IUserManager $userManager
     */
    private $userManager;

    /**
     * @var \OCP\IGroupManager
     */
    private $groupManager;

    /**
     * @var AppService $appService
     */
    private $appService;

    /**
     * @var \OCA\UserCAS\User\Backend $backend
     */
    private $backend;


    /**
     * UserService constructor.
     *
     * @param $appName
     * @param IConfig $config
     * @param IUserManager $userManager
     * @param IUserSession $userSession
     * @param IGroupManager $groupManager
     * @param AppService $appService
     * @param Backend $backend
     */
    public function __construct($appName, IConfig $config, IUserManager $userManager, IUserSession $userSession, IGroupManager $groupManager, AppService $appService, Backend $backend)
    {

        $this->appName = $appName;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
        $this->appService = $appService;
        $this->backend = $backend;
    }

    /**
     * Login hook method.
     *
     * @param $request
     * @param string $uid
     * @param string $password
     * @return bool
     */
    public function login($request, $uid, $password = '')
    {

        \OCP\Util::writeLog('cas', 'phpCAS login function step 1.', \OCP\Util::DEBUG);

        try {

            $loginSuccessful = $this->userSession->login($uid, $password);

            \OCP\Util::writeLog('cas', 'phpCAS login function result: ' . $loginSuccessful, \OCP\Util::DEBUG);

            if ($loginSuccessful) {

                return $this->userSession->createSessionToken($request, $this->userSession->getUser()->getUID(), $uid, $password);
            }

            \OCP\Util::writeLog('cas', 'phpCAS login function not successful.', \OCP\Util::DEBUG);

            return FALSE;
        } catch (\OC\User\LoginException $e) {

            \OCP\Util::writeLog('cas', 'Owncloud could not log in the user with UID: ' . $uid . '. Exception thrown with code: ' . $e->getCode() . ' and message: ' . $e->getMessage() . '.', \OCP\Util::ERROR);

            return FALSE;
        }
    }

    /**
     * IsLoggedIn method.
     *
     * @return boolean
     */
    public function isLoggedIn()
    {

        return $this->userSession->isLoggedIn();
    }

    /**
     * @param string $userId
     * @return boolean|\OCP\IUser the created user or false
     */
    public function create($userId)
    {

        $randomPassword = \OC::$server->getSecureRandom()->getMediumStrengthGenerator()->generate(20);

        return $this->userManager->createUser($userId, $randomPassword);
    }

    /**
     * @param string $userId
     * @return mixed
     */
    public function userExists($userId)
    {

        return $this->userManager->userExists($userId);
    }

    /**
     * Update the user
     *
     * @param \OCP\IUser $user
     * @param array $attributes
     */
    public function updateUser($user, $attributes)
    {

        $userId = $user->getUID();

        /*$attributesString = '';
        foreach ($attributes as $key => $attribute) {

            $attributesString .= $key . ': ' . $attribute . '; ';
        }*/

        \OCP\Util::writeLog('cas', 'Updating data of the user: ' . $userId, \OCP\Util::DEBUG);
        #\OCP\Util::writeLog('cas', 'Attributes: ' . $attributesString, \OCP\Util::DEBUG);

        if (isset($attributes['cas_email']) && is_object($user)) {

            $this->updateMail($user, $attributes['cas_email']);
        }
        if (isset($attributes['cas_name']) && is_object($user)) {

            $this->updateName($user, $attributes['cas_name']);
        }
        if (isset($attributes['cas_groups']) && is_object($user)) {

            $this->updateGroups($user, $attributes['cas_groups'], $this->config->getAppValue($this->appName, 'cas_protected_groups'));
        }

        \OCP\Util::writeLog('cas', 'Updating data finished.', \OCP\Util::DEBUG);
    }

    /**
     * Update the eMail address
     *
     * @param \OCP\IUser $user
     * @param string|array $email
     */
    private function updateMail($user, $email)
    {

        if (is_array($email)) {

            $email = $email[0];
        }

        if ($email !== $user->getEMailAddress()) {

            $user->setEMailAddress($email);
            \OCP\Util::writeLog('cas', 'Set email "' . $email . '" for the user: ' . $user->getUID(), \OCP\Util::DEBUG);
        }
    }

    /**
     * Update the display name
     *
     * @param \OCP\IUser $user
     * @param string| $name
     */
    private function updateName($user, $name)
    {

        if (is_array($name)) {

            $name = $name[0];
        }

        if ($name !== $user->getDisplayName()) {

            $user->setDisplayName($name);
            \OCP\Util::writeLog('cas', 'Set Name: ' . $name . ' for the user: ' . $user->getUID(), \OCP\Util::DEBUG);
        }
    }

    /**
     * Gets an array of groups and will try to add the group to OC and then add the user to the groups.
     *
     * @param \OCP\IUser $user
     * @param string $groups
     * @param string $protectedGroups
     * @param bool $justCreated
     */
    private function updateGroups($user, $groups, $protectedGroups = '', $justCreated = false)
    {

        if (is_string($groups)) $groups = explode(",", $groups);
        if (is_string($protectedGroups)) $protectedGroups = explode(",", $protectedGroups);

        $uid = $user->getUID();

        if (!$justCreated) {

            $oldGroups = $this->groupManager->getUserGroups($user);

            foreach ($oldGroups as $group) {

                if ($group instanceof \OCP\IGroup) {

                    $groupId = $group->getGID();

                    if (!in_array($groupId, $protectedGroups) && !in_array($groupId, $groups)) {

                        $group->removeUser($user);

                        \OCP\Util::writeLog('cas', 'Removed "' . $uid . '" from the group "' . $groupId . '"', \OCP\Util::DEBUG);
                    }
                }
            }
        }

        foreach ($groups as $group) {

            $groupObject = NULL;

            if (preg_match('/[^a-zA-Z0-9 _\.@\-]/', $group)) {

                \OCP\Util::writeLog('cas', 'Invalid group "' . $group . '", allowed chars "a-zA-Z0-9" and "_.@-" ', \OCP\Util::DEBUG);
            } else {

                if (!$this->groupManager->isInGroup($uid, $group)) {

                    if (!$this->groupManager->groupExists($group)) {

                        $groupObject = $this->groupManager->createGroup($group);
                        \OCP\Util::writeLog('cas', 'New group created: ' . $group, \OCP\Util::DEBUG);
                    } else {

                        $groupObject = $this->groupManager->get($group);
                    }

                    $groupObject->addUser($user);
                    \OCP\Util::writeLog('cas', 'Added "' . $uid . '" to the group "' . $group . '"', \OCP\Util::DEBUG);
                }
            }
        }
    }

    /**
     * Register User Backend.
     */
    public function registerBackend()
    {

        if (!$this->appService->isCasInitialized()) $this->appService->init();

        $this->userManager->registerBackend($this->backend);
    }
}