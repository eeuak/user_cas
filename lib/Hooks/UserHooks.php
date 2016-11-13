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

namespace OCA\User_CAS\Hooks;

/**
 * Class User_CAS_Hooks
 *
 * @package OCA\User_CAS\Hooks
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4
 */
class UserHooks
{

    /**
     * @var \OC\User\Manager $userManager
     */
    private $userManager;

    /**
     * @var \OC\User\Session $userSession
     */
    private $userSession;

    /**
     * @var \OCA\User_CAS\Service\UserService $userService
     */
    private $userService;

    /**
     * @var \OCA\User_CAS\UserCAS $ocUserCas
     */
    private $ocUserCas;


    /**
     * UserHooks constructor.
     *
     * @param \OC\User\Manager $userManager
     * @param \OC\User\Session $userSession
     * @param \OCA\User_CAS\Service\UserService $userService
     */
    public function __construct(\OC\User\Manager $userManager, \OC\User\Session $userSession, \OCA\User_CAS\Service\UserService $userService)
    {
        $this->userManager = $userManager;
        $this->userSession = $userSession;
        $this->userService = $userService;
        $this->ocUserCas = \OCA\User_CAS\UserCAS::getInstance();
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
     * postLogin method.
     * @param \OC\User\User $user
     * @return bool
     */
    public function postLogin(\OC\User\User $user)
    {

        $uid = $user->getUID();

        if (phpCAS::isAuthenticated()) {
            // $cas_attributes may vary in name, therefore attributes are fetched to $attributes
            $cas_attributes = \phpCAS::getAttributes();
            $cas_uid = \phpCAS::getUser();

            // parameters
            $attributes = array();


            if ($cas_uid === $uid) {

                \OCP\Util::writeLog('cas', 'attr  \"' . implode(',', $cas_attributes) . '\" for the user: ' . $uid, \OCP\Util::DEBUG);

                if (array_key_exists($this->ocUserCas->displayNameMapping, $cas_attributes)) {

                    $attributes['cas_name'] = $cas_attributes[$this->ocUserCas->displayNameMapping];
                }
                else {

                    if(isset($cas_attributes['cn'])) $attributes['cas_name'] = $cas_attributes['cn'];
                }

                if (array_key_exists($this->ocUserCas->mailMapping, $cas_attributes)) {

                    $attributes['cas_email'] = $cas_attributes[$this->ocUserCas->mailMapping];
                }
                else {

                    if(isset($cas_attributes['mail'])) $attributes['cas_email'] = $cas_attributes['mail'];
                }


                // Group handling
                if (array_key_exists($this->ocUserCas->groupMapping, $cas_attributes)) {
                    $attributes['cas_groups'] = $cas_attributes[$this->ocUserCas->groupMapping];
                } else if (!empty($this->ocUserCas->defaultGroup)) {
                    $attributes['cas_groups'] = array($this->ocUserCas->defaultGroup);
                    \OCP\Util::writeLog('cas', 'Using default group "' . $this->ocUserCas->defaultGroup . '" for the user: ' . $uid, \OCP\Util::DEBUG);
                }

                // Autocreate user if needed
                if (!$this->userService->userExists($uid) && $this->ocUserCas->autocreate) {

                    // create users if they do not exist
                    if (preg_match('/[^a-zA-Z0-9 _\.@\-]/', $uid)) {

                        \OCP\Util::writeLog('cas', 'Invalid username "' . $uid . '", allowed chars "a-zA-Z0-9" and "_.@-" ', \OCP\Util::DEBUG);
                        return false;
                    }
                    else {

                        $random_password = \OCP\Util::generateRandomBytes(20);

                        \OCP\Util::writeLog('cas', 'Creating new user with UID: ' . $uid, \OCP\Util::DEBUG);

                        $this->userManager->create($uid, $random_password);

                        // after creating the user, fill the attributes
                        if ($this->userManager->userExists($uid)) {

                            $this->userService->updateUser($uid, $attributes);
                        }
                    }
                }

                // Try to update user attributes
                if ($this->ocUserCas->updateUserData){

                    $this->userService->updateUser($cas_uid, $attributes);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Logout hook method.
     *
     * @return bool
     */
    public function logout()
    {
        if (!$this->ocUserCas->disableLogout && \phpCAS::isAuthenticated()) {

            \phpCAS::logout();
        }

        return true;
    }
}