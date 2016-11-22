<?php

\OCP\Util::addscript('user_cas', 'settings');
\OCP\Util::addStyle('user_cas', 'settings');

/** @var \OCA\UserCAS\AppInfo\Application $app */
$app = new \OCA\UserCAS\AppInfo\Application();

$controller = $app->getContainer()->query('SettingsController');

return $controller->displayPanel();