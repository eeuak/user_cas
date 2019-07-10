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
use OCA\UserCAS\Service\LoggingService;
use OCP\IUserBackend;
use OCP\User\IProvidesHomeBackend;
use OCP\UserInterface;


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
class Backend extends \OC\User\Backend implements UserInterface, IUserBackend, IProvidesHomeBackend, UserCasBackendInterface
{

    /**
     * @var \OCA\UserCAS\Service\LoggingService $loggingService
     */
    protected $loggingService;

    /**
     * @var \OCA\UserCAS\Service\AppService $appService
     */
    protected $appService;


    /**
     * @var array $possibleActions
     */
    protected $possibleActions = [
        self::CREATE_USER => 'createUser',
        self::CHECK_PASSWORD => 'checkPassword',
        self::GET_HOME => 'getHome',
    ];


    /**
     * Backend constructor.
     *
     * @param LoggingService $loggingService
     * @param AppService $appService
     */
    public function __construct(LoggingService $loggingService, AppService $appService)
    {

        $this->loggingService = $loggingService;
        $this->appService = $appService;
    }


    /**
     * Backend name to be shown in user management
     *
     * @return string the name of the backend to be shown
     */
    public function getBackendName()
    {

        return "CAS";
    }


    /**
     * Check the password
     *
     * @param string $loginName
     * @param string $password
     * @return string|bool The users UID or false
     */
    public function checkPassword(string $loginName, string $password)
    {

        if (!$this->appService->isCasInitialized()) {

            try {

                $this->appService->init();
            } catch (PhpUserCasLibraryNotFoundException $e) {

                $this->loggingService->write(\OCA\UserCas\Service\LoggingService::ERROR, 'Fatal error with code: ' . $e->getCode() . ' and message: ' . $e->getMessage());

                return FALSE;
            }
        }

        if (\phpCAS::isInitialized()) {

            if ($loginName === FALSE) {

                $this->loggingService->write(\OCA\UserCas\Service\LoggingService::ERROR, 'phpCAS returned no user.');
            }

            if (\phpCAS::checkAuthentication()) {

                $casUid = \phpCAS::getUser();

                if ($casUid === $loginName) {

                    $this->loggingService->write(\OCA\UserCas\Service\LoggingService::DEBUG, 'phpCAS user password has been checked.');

                    return $loginName;
                }
            }

            return FALSE;
        } else {

            $this->loggingService->write(\OCA\UserCas\Service\LoggingService::ERROR, 'phpCAS has not been initialized.');

            return FALSE;
        }
    }


    /**
     * Get a users absolute home folder path
     *
     * @param string $uid The username
     * @return string|null
     * @since 1.8.0
     */
    public function getHome($uid)
    {
        return \OC::$server->getConfig()->getSystemValue("datadirectory", \OC::$SERVERROOT . "/data") . '/' . $uid;

    }

    /**
     * Creates a user
     *
     * @param string $uid
     * @param string $password
     * @return bool TRUE
     */
    public function createUser($uid, $password)
    {
        return TRUE;
    }
}
