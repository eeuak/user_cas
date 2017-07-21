<?php

/**
 * ownCloud - user_cas
 *
 * @author Sixto Martin <sixto.martin.garcia@gmail.com>
 * @author Felix Rupp <kontakt@felixrupp.com>
 *
 * @copyright Sixto Martin Garcia. 2012
 * @copyright Leonis. 2014 <devteam@leonis.at>
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

$app = new \OCA\UserCAS\AppInfo\Application();
$c = $app->getContainer();

if (\OCP\App::isEnabled($c->getAppName())) {

    require_once __DIR__ . '/../vendor/jasig/phpcas/CAS.php';

    $appService = $c->query('AppService');
    $userService = $c->query('UserService');

    // Check for enforced authentication
    /*if ($appService->isEnforceAuthentication()) {

        // Initialize app
        $appService->init();

        if(!\phpCAS::isAuthenticated()) {

            header("Location: ".$appService->linkToRoute($c->getAppName() . '.authentication.casLogin'));
        }
    }*/

    // Register User Backend
    $appService->registerBackend();

    // Register UserHooks
    $c->query('UserHooks')->register();

    // Register Admin Panel
    \OCP\App::registerAdmin($c->getAppName(), 'admin');

    // Register alternative LogIn
    $urlParams = "";
    if (isset($_GET['redirect_url'])) {

        $urlParams .= "?redirect_url=" . $_GET['redirect_url'];
    }

    \OC_App::registerLogIn(array('href' => $appService->linkToRoute($c->getAppName() . '.authentication.casLogin') . $urlParams, 'name' => 'CAS Login'));
}