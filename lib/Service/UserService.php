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
     * @var \OC\User\Session $userSession
     */
    private $userSession;

    /**
     * @var \OC\User\Manager $userManager
     */
    private $userManager;


    /**
     * UserService constructor.
     *
     * @param $userSession
     */
    public function __construct($appName, \OCP\IConfig $config, \OC\User\Manager $userManager, \OC\User\Session $userSession)
    {

        $this->appName = $appName;
        $this->config = $config;
        $this->userManager = $userManager;
        $this->userSession = $userSession;
    }

    /**
     * Login hook method.
     *
     * @param string $uid
     * @param string $password
     * @return boolean
     */
    public function login($uid, $password = NULL)
    {

        try {

            return $this->userSession->login($uid, $password);

        } catch (\OC\User\LoginException $e) {

            \OCP\Util::writeLog('cas', 'Owncloud could not log in the user with UID: ' . $uid . '. Exception thrown with code: ' . $e->getCode() . ' and message: ' . $e->getMessage() . '.', \OCP\Util::ERROR);

            return FALSE;
        }
    }

    /**
     * Logout hook method.
     */
    public function logout()
    {
        $this->userSession->logout();
    }

    /**
     * @param $userId
     * @return boolean|\OCP\IUser the created user or false
     */
    public function create($userId)
    {

        $randomPassword = \OCP\Util::generateRandomBytes(20);

        $user = $this->userManager->createUser($userId, $randomPassword);

        return $user;
    }

    /**
     * Update the user
     *
     * @param $uid
     * @param $attributes
     */
    public function updateUser($uid, $attributes)
    {

        $user = $this->userSession->getUser();

        \OCP\Util::writeLog('cas', 'Updating data of the user: ' . $uid, \OCP\Util::DEBUG);
        \OCP\Util::writeLog('cas', 'attr: ' . implode(",", $attributes), \OCP\Util::DEBUG);

        if (isset($attributes['cas_email']) && is_object($user)) {

            $this->updateMail($user, $attributes['cas_email']);
        }
        if (isset($attributes['cas_name']) && is_object($user)) {

            $this->updateName($user, $attributes['cas_name']);
        }
        if (isset($attributes['cas_groups']) && is_object($user)) {

            $this->updateGroups($user, $attributes['cas_groups'], $this->config->getAppValue($this->appName, 'cas_protected_groups'), false);
        }
    }

    /**
     * Update the eMail address
     *
     * @param $user
     * @param $email
     */
    private function updateMail($user, $email)
    {

        if ($email !== $user->getEMailAddress()) {

            $user->setEMailAddress($email);
            \OCP\Util::writeLog('cas', 'Set email "' . $email . '" for the user: ' . $user->getUID(), \OCP\Util::DEBUG);
        }
    }

    /**
     * Update the display name
     *
     * @param $user
     * @param $name
     */
    private function updateName($user, $name)
    {

        $user->setDisplayName($name);

        \OCP\Util::writeLog('cas', 'Set Name: ' . $name . ' for the user: ' . $user->getUID(), \OCP\Util::DEBUG);
    }

    /**
     * Gets an array of groups and will try to add the group to OC and then add the user to the groups.
     *
     * @param $user
     * @param $groups
     * @param array $protectedGroups
     * @param bool $justCreated
     */
    private function updateGroups($user, $groups, $protectedGroups = array(), $justCreated = false)
    {

        $uid = $user->getUID();

        if (!$justCreated) {

            $oldGroups = \OC_Group::getUserGroups($uid);

            foreach ($oldGroups as $group) {

                if (!in_array($group, $protectedGroups) && !in_array($group, $groups)) {

                    \OC_Group::removeFromGroup($uid, $group);
                    \OCP\Util::writeLog('cas', 'Removed "' . $uid . '" from the group "' . $group . '"', \OCP\Util::DEBUG);
                }
            }
        }

        foreach ($groups as $group) {

            if (preg_match('/[^a-zA-Z0-9 _\.@\-]/', $group)) {

                \OCP\Util::writeLog('cas', 'Invalid group "' . $group . '", allowed chars "a-zA-Z0-9" and "_.@-" ', \OCP\Util::DEBUG);
            } else {

                if (!\OC_Group::inGroup($uid, $group)) {

                    if (!OC_Group::groupExists($group)) {

                        \OC_Group::createGroup($group);
                        \OCP\Util::writeLog('cas', 'New group created: ' . $group, \OCP\Util::DEBUG);
                    }

                    \OC_Group::addToGroup($uid, $group);
                    \OCP\Util::writeLog('cas', 'Added "' . $uid . '" to the group "' . $group . '"', \OCP\Util::DEBUG);
                }
            }
        }
    }
}