<?php

namespace OCA\UserCAS\AppInfo;

/**
 * ownCloud - user_cas
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 */

$application = new \OCA\UserCAS\AppInfo\Application();
$application->registerRoutes($this, array(
    'routes' => [
        array('name' => 'settings#saveSettings', 'url' => '/settings/save', 'verb' => 'POST'),
        array('name' => 'authentication#casLogin', 'url' => '/login', 'verb' => 'GET')
    ]
));