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

namespace OCA\User_CAS\Service;

/**
 * Class UserService
 *
 * @package OCA\User_CAS\Service
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4
 */
class UserService
{

    /**
     * @var \OC\User\Session $userSession
     */
    private $userSession;

    /**
     * @var \OC\User\Manager $userManager
     */
    private $userManager;

    /**
     * @var \OCA\User_CAS\UserCAS $ocUserCas
     */
    private $ocUserCas;


    /**
     * UserService constructor.
     *
     * @param $userSession
     */
    public function __construct(\OC\User\Manager $userManager, \OC\User\Session $userSession)
    {

        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->ocUserCas = \OCA\User_CAS\UserCAS::getInstance();
    }

    /**
     * Login hook method.
     *
     * @param string $userId
     * @param string $password
     * @return boolean|null
     */
    public function login($userId, $password = NULL)
    {

        try {

            return $this->userSession->login($userId, $password);
        } catch (\OC\User\LoginException $e) {

            \OCP\Util::writeLog('cas', 'Owncloud could not log in the user with UID: ' . $userId . '. Exception thrown with code: ' . $e->getCode() . ' and message: ' . $e->getMessage() . '.', \OCP\Util::DEBUG);
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
     * @param string $userId
     * @param string $password
     * @return mixed
     */
    public function create($userId, $password, $attributes)
    {

        $user = $this->userManager->createUser($userId, $password);

        $this->updateUser($user, $attributes);

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

            $this->updateGroups($user, $attributes['cas_groups'], $this->ocUserCas->protectedGroups, false);
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
     * @param array $protected_groups
     * @param bool $just_created
     */
    private function updateGroups($user, $groups, $protected_groups = array(), $just_created = false)
    {

        $uid = $user->getUID();

        if (!$just_created) {

            $old_groups = OC_Group::getUserGroups($uid);

            foreach ($old_groups as $group) {

                if (!in_array($group, $protected_groups) && !in_array($group, $groups)) {

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