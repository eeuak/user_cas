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

namespace OCA\UserCAS\User;

use \OC\User\Manager;
use OCA\UserCAS\Service\LoggingService;


/**
 * Class Backend
 *
 * @package OCA\UserCAS\User
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4.0
 */
class Backend extends \OC\User\Backend implements \OCP\IUserBackend
{

    /**
     * @var \OC\User\Manager $userManager
     */
    private $userManager;

    /**
     * @var \OCA\UserCAS\Service\LoggingService $loggingService
     */
    private $loggingService;


    /**
     * Backend constructor.
     * @param Manager $userManager
     * @param LoggingService $loggingService
     */
    public function __construct(Manager $userManager, LoggingService $loggingService)
    {

        $this->userManager = $userManager;
        $this->loggingService = $loggingService;
    }


    /**
     * Backend name to be shown in user management
     * @return string the name of the backend to be shown
     * @since 8.0.0
     */
    public function getBackendName()
    {

        return "CAS";
    }


    /**
     * @param string $uid
     * @param string $password
     * @return bool
     */
    public function checkPassword($uid, $password)
    {

        if (\phpCAS::isInitialized()) {

            if (!\phpCAS::isAuthenticated()) {

                $this->loggingService->write(\OCP\Util::ERROR, 'phpCAS user has not been authenticated.');
                #\OCP\Util::writeLog('cas', 'phpCAS user has not been authenticated.', \OCP\Util::ERROR);
                return FALSE;
            }

            if ($uid === FALSE) {

                $this->loggingService->write(\OCP\Util::ERROR, 'phpCAS returned no user.');
                #\OCP\Util::writeLog('cas', 'phpCAS returned no user.', \OCP\Util::ERROR);
                return FALSE;
            }

            $casUid = \phpCAS::getUser();

            if ($casUid === $uid) {

                $this->loggingService->write(\OCP\Util::ERROR, 'phpCAS user password has been checked.');
                #\OCP\Util::writeLog('cas', 'phpCAS user password has been checked.', \OCP\Util::ERROR);

                return $uid;
            }
        } else {

            $this->loggingService->write(\OCP\Util::ERROR, 'phpCAS has not been initialized.');
            #\OCP\Util::writeLog('cas', 'phpCAS has not been initialized.', \OCP\Util::ERROR);
            return FALSE;
        }
    }

    /**
     * @param string $uid
     * @return NULL|string
     */
    public function getDisplayName($uid)
    {
        $user = $this->userManager->get($uid);

        if (!is_null($user)) return $user->getDisplayName();

        return NULL;
    }

    /**
     * @param string $uid
     * @param string $displayName
     */
    public function setDisplayName($uid, $displayName)
    {
        $user = $this->userManager->get($uid);

        if (!is_null($user)) $user->setDisplayName($displayName);
    }

    /**
     * Delete a user.
     *
     * @param string $uid The username of the user to delete
     * @return bool
     *
     * Deletes a user
     */
    public function deleteUser($uid)
    {
        $user = $this->userManager->get($uid);

        return $user->delete();
    }

    /**
     * Get the user's home directory.
     *
     * @param string $uid the username
     * @return boolean|string
     */
    public function getHome($uid)
    {
        $user = $this->userManager->get($uid);

        if (!is_null($user)) return $user->getHome();

        return FALSE;
    }
}