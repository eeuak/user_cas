<?php

namespace OCA\User_CAS\AppInfo;

//\OCP\Util::addscript('user_cas', 'settings');
//\OCP\Util::addStyle('user_cas', 'settings');

$app = new \OCA\User_CAS\AppInfo\Application();

$controller = $app->getContainer()->query('SettingsController');

return $controller->displayPanel()->render();