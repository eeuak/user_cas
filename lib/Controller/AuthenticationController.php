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

namespace OCA\UserCAS\Controller;

use OCA\UserCAS\Service\AppService;
use \OCP\IRequest;
use \OCP\AppFramework\Http\RedirectResponse;
use \OCP\AppFramework\Http;
use \OCP\AppFramework\Controller;
use \OCP\IGroupManager;
use \OCP\IL10N;
use \OCP\IConfig;
use \OCP\IUser;
use \OCP\IURLGenerator;
use \OCP\IUserManager;

use \OCA\UserCAS\Service\UserService;


/**
 * Class AuthenticationController
 *
 * @package OCA\UserCAS\Controller
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4.0
 */
class AuthenticationController extends Controller
{

    protected $appName;

    private $config;
    private $userService;
    private $appService;

    public function __construct($appName, IRequest $request, IConfig $config, UserService $userService, AppService $appService)
    {
        $this->appName = $appName;
        $this->config = $config;
        $this->userService = $userService;
        $this->appService = $appService;
        parent::__construct($appName, $request);

        if (!$this->appService->isCasInitialized()) $this->appService->init();
    }

    /**
     * Login method.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @return RedirectResponse
     */
    public function casLogin()
    {

        if(!$this->userService->isLoggedIn()) {

            if ($this->appService->isCasInitialized()) {

                try {

                    if (\phpCAS::isAuthenticated()) {

                        $userName = \phpCAS::getUser();

                        \OCP\Util::writeLog('cas', "phpCAS user ".$userName." has been authenticated.", \OCP\Util::DEBUG);

                        $isLoggedIn = $this->userService->login($userName, NULL);

                        if ($isLoggedIn) { //TODO Fix owncloud login mechanism. Users are NOT logged in. Don’t know why!

                            \OCP\Util::writeLog('cas', "phpCAS user has been authenticated against owncloud.", \OCP\Util::DEBUG);

                            $url = $this->appService->linkToRoute('files.view.index');

                            return new RedirectResponse($url);
                        }
                        else { # Not authenticated against owncloud

                            \OCP\Util::writeLog('cas', "phpCAS user has not been authenticated against owncloud.", \OCP\Util::ERROR);

                            return new RedirectResponse("./");
                        }
                    } else { # Not authenticated against CAS

                        \OCP\Util::writeLog('cas', "phpCAS user is not authenticated, redirect to CAS server.", \OCP\Util::DEBUG);

                        \phpCAS::forceAuthentication();
                    }
                } catch (\CAS_Exception $e) {

                    \OCP\Util::writeLog('cas', "phpCAS has thrown an exception with code: " . $e->getCode() . " and message: " . $e->getMessage() . ".", \OCP\Util::ERROR);

                    return new RedirectResponse("./");
                }
            } else { # Not casInitialized

                \OCP\Util::writeLog('cas', "phpCAS has not been initialized correctly. Can not log in.", \OCP\Util::ERROR);

                return new RedirectResponse("./");
            }
        }
        else {

            \OCP\Util::writeLog('cas', "phpCAS user is already authenticated against owncloud.", \OCP\Util::DEBUG);
        }
    }
}