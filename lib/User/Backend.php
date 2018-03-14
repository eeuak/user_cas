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

use OCA\UserCAS\Exception\PhpCas\PhpUserCasLibraryNotFoundException;
use OCA\UserCAS\Service\AppService;
use \OCP\IUserManager;
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
     * @var \OCP\IUserManager $userManager
     */
    private $userManager;

    /**
     * @var \OCA\UserCAS\Service\LoggingService $loggingService
     */
    private $loggingService;

    /**
     * @var \OCA\UserCAS\Service\AppService $appService
     */
    private $appService;

    /**
     * Backend constructor.
     * @param IUserManager $userManager
     * @param LoggingService $loggingService
     * @param AppService $appService
     */
    public function __construct(IUserManager $userManager, LoggingService $loggingService, AppService $appService)
    {

        $this->userManager = $userManager;
        $this->loggingService = $loggingService;
        $this->appService = $appService;
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

        $script = $_SERVER['SCRIPT_FILENAME'];
        $requestUri = $_SERVER['REQUEST_URI'];

        // Let all OCS API Users pass
        if(strpos($script, "ocs") || strpos($requestUri, "oc.js")) {

            $this->loggingService->write(\OCP\Util::DEBUG, 'Access through OCS. Let it pass.');
            return TRUE;
        }

        if (!$this->appService->isCasInitialized()) {

            try {

                $this->appService->init();
            } catch (PhpUserCasLibraryNotFoundException $e) {

                $this->loggingService->write(\OCP\Util::FATAL, 'Fatal error with code: ' . $e->getCode() . ' and message: ' . $e->getMessage());

                header("Location: " . $this->appService->getAbsoluteURL('/'));
                die();
            }
        }

        if (\phpCAS::isInitialized()) {

            if (!\phpCAS::isAuthenticated()) {

                $this->loggingService->write(\OCP\Util::ERROR, 'phpCAS user has not been authenticated.');
                #\OCP\Util::writeLog('cas', 'phpCAS user has not been authenticated.', \OCP\Util::ERROR);
            }

            if ($uid === FALSE) {

                $this->loggingService->write(\OCP\Util::ERROR, 'phpCAS returned no user.');
                #\OCP\Util::writeLog('cas', 'phpCAS returned no user.', \OCP\Util::ERROR);
            }

            if(\phpCAS::checkAuthentication()) {

                $casUid = \phpCAS::getUser();

                if ($casUid === $uid) {

                    $this->loggingService->write(\OCP\Util::ERROR, 'phpCAS user password has been checked.');
                    #\OCP\Util::writeLog('cas', 'phpCAS user password has been checked.', \OCP\Util::ERROR);

                    return $uid;
                }
            }

            return FALSE;
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