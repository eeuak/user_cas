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

namespace OCA\User_CAS;

/**
 * Class User_CAS
 *
 * @package OCA\User_CAS
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @pattern singleton
 *
 * @since 1.4.0
 */
class UserCAS extends \OC\User\Backend
{

    /**
     * @var \OC\User\Manager $userManager
     */
    private $userManager;

    /**
     * @var \OCA\User_CAS\Service\UserService $userService
     */
    private $userService;


    // cached settings
    public $autocreate;
    public $updateUserData;
    public $protectedGroups;
    public $defaultGroup;
    public $displayNameMapping;
    public $mailMapping;
    public $groupMapping;
    public $disableLogout;

    /**
     * Holds the only instance of itself
     *
     * @var null|UserCAS
     */
    private static $instance = NULL;

    /**
     * @var bool
     */
    private $initialized= FALSE;

    /**
     * @var bool
     * @deprecated
     */
    private $ldapBackendAdapter = FALSE;

    /**
     * @var bool
     * @deprecated
     */
    private $cas_link_to_ldap_backend = FALSE;


    /**
     * Get singleton instance.
     *
     * @return null|UserCAS
     */
    public static function getInstance()
    {
        if (static::$instance === NULL) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * User_CAS constructor.
     *
     * @return bool TRUE|FALSE Wether initialized or not
     */
    protected function __construct()
    {
        $this->autocreate = \OCP\Config::getAppValue('user_cas', 'cas_autocreate', true);
        #$this->cas_link_to_ldap_backend = \OCP\Config::getAppValue('user_cas', 'cas_link_to_ldap_backend', false);
        $this->updateUserData = \OCP\Config::getAppValue('user_cas', 'cas_update_user_data', true);
        $this->defaultGroup = \OCP\Config::getAppValue('user_cas', 'cas_default_group', '');
        $this->protectedGroups = explode(',', str_replace(' ', '', \OCP\Config::getAppValue('user_cas', 'cas_protected_groups', '')));
        $this->mailMapping = \OCP\Config::getAppValue('user_cas', 'cas_email_mapping', '');
        $this->displayNameMapping = \OCP\Config::getAppValue('user_cas', 'cas_displayName_mapping', '');
        $this->groupMapping = \OCP\Config::getAppValue('user_cas', 'cas_group_mapping', '');
        $this->disableLogout = \OCP\Config::getAppValue('user_cas', 'cas_disable_logout', false);

        $this->userManager = new \OC\User\Manager();
        $this->userService = new \OCA\User_CAS\Service\UserService();

        $this->init();
    }

    /**
     * Private clone method to prevent cloning of the instance of the
     * *Singleton* instance.
     *
     * @return void
     */
    private function __clone()
    {
    }

    /**
     * Private unserialize method to prevent unserializing of the *Singleton*
     * instance.
     *
     * @return void
     */
    private function __wakeup()
    {
    }

    /**
     * Get the initialize property and initialize beforehand if necessary.
     *
     * @return bool TRUE|FALSE Wether initialized or not
     */
    public function isInitialized()
    {
        if (!$this->initialized) {

            $this->init();
        }

        return $this->initialized;
    }

    /**
     * Init method.
     *
     * @return bool
     */
    private function init()
    {

        \OCP\App::registerAdmin('user_cas', 'settings');

        // Register User Backend
        $this->userManager->registerBackend('CAS');

        // Gather all app config values
        $casVersion = \OCP\Config::getAppValue('user_cas', 'cas_server_version', '2.0');
        $casHostname = \OCP\Config::getAppValue('user_cas', 'cas_server_hostname', $_SERVER['SERVER_NAME']);
        $casPort = \OCP\Config::getAppValue('user_cas', 'cas_server_port', 443);
        $casPath = \OCP\Config::getAppValue('user_cas', 'cas_server_path', '/cas');
        $casDebugFile = \OCP\Config::getAppValue('user_cas', 'cas_debug_file', '');
        $casCertPath = \OCP\Config::getAppValue('user_cas', 'cas_cert_path', '');
        //$php_cas_path = \OCP\Config::getAppValue('user_cas', 'cas_php_cas_path', 'CAS.php');
        $cas_service_url = \OCP\Config::getAppValue('user_cas', 'cas_service_url', '');

        if(!class_exists('\\phpCAS')) {

            \OCP\Util::writeLog('cas', 'phpCAS library could not be loaded. The class was not found.', \OCP\Util::ERROR);

            $this->initialized = FALSE;
        }

        /*if (!class_exists('phpCAS')) {

            if (empty($php_cas_path)) $php_cas_path = 'CAS.php';

            \OCP\Util::writeLog('cas', "Try to load phpCAS library ($php_cas_path)", \OCP\Util::DEBUG);

            include_once($php_cas_path);

            if (!class_exists('phpCAS')) {
                \OCP\Util::writeLog('cas', 'Failed to load phpCAS library!', \OCP\Util::ERROR);
                return false;
            }
        }*/

        try {

            if ($casDebugFile !== '') {
                \phpCAS::setDebug($casDebugFile);
            }

            \phpCAS::client($casVersion, $casHostname, intval($casPort), $casPath, false);

            if (!empty($cas_service_url)) {
                \phpCAS::setFixedServiceURL($cas_service_url);
            }

            if (!empty($casCertPath)) {
                \phpCAS::setCasServerCACert($casCertPath);
            } else {
                \phpCAS::setNoCasServerValidation();
            }

            \OCP\Util::writeLog('cas', "phpCAS has been successfully initialized.", \OCP\Util::DEBUG);

            $this->initialized = TRUE;

        } catch(\CAS_Exception $e) {

            \OCP\Util::writeLog('cas', "phpCAS has thrown an exception with code: ".$e->getCode()." and message: ".$e->getMessage().".", \OCP\Util::ERROR);

            $this->initialized = FALSE;
        }
    }

    /**
     * Check if login should be enforced using user_cas
     *
     * @return bool TRUE|FALSE
     */
    public function isEnforceAuthentication()
    {
        if (OC::$CLI) {
            return false;
        }

        if (\OCP\Config::getAppValue('user_cas', 'cas_force_login', false) !== 'on') {
            return false;
        }

        if (\OCP\User::isLoggedIn() || isset($_GET['admin_login'])) {
            return false;
        }

        $script = basename($_SERVER['SCRIPT_FILENAME']);
        return !in_array(
            $script,
            array(
                'cron.php',
                'public.php',
                'remote.php',
                'status.php',
            )
        );
    }

    /**
     * Login method.
     *
     * @param $userName
     */
    public function login($userName) {

        $this->userService->login($userName, NULL);
    }



    // Deprecated methods for LDAP Support:

    /**
     * Check if the ldapBackendAdapter is initialized
     *
     * @return bool
     *
     * @deprecated
     */
    private function ldapBackendAdapterIsInitialized()
    {
        if (!$this->cas_link_to_ldap_backend) {
            return false;
        }
        if ($this->ldapBackendAdapter === false) {
            $this->ldapBackendAdapter = new LdapBackendAdapter();
        }
        return true;
    }

    /**
     * Check the password against the ldap backend
     *
     * @param $uid
     * @param $password
     * @return bool
     *
     * @deprecated
     */
    public function checkPassword($uid, $password)
    {
        if (!$this->isInitialized()) {

            return false;
        }

        if (!\phpCAS::isAuthenticated()) {

            return false;
        }

        $uid = \phpCAS::getUser();

        if ($uid === false) {

            \OCP\Util::writeLog('cas', 'phpCAS returned no user!', \OCP\Util::ERROR);
            return false;
        }

        if ($this->ldapBackendAdapterIsInitialized()) {

            \OCP\Util::writeLog('cas', "phpCAS searching for CAS user with UID: '$uid' in LDAP.", \OCP\Util::DEBUG);

            //Retrieve user in LDAP directory
            $ocname = $this->ldapBackendAdapter->getUuid($uid);

            if (($uid !== false) && ($ocname !== false)) {

                \OCP\Util::writeLog('cas', "phpCAS found CAS user with UID: '$uid' in LDAP with name: '$ocname'.", \OCP\Util::DEBUG);
                return $ocname;
            }
        }

        return $uid;
    }
}