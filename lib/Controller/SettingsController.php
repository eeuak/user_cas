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

use \OCP\IRequest;
use \OCP\AppFramework\Http\TemplateResponse;
use \OCP\AppFramework\Http\DataResponse;
use \OCP\AppFramework\Http;
use \OCP\AppFramework\Controller;
use \OCP\IGroupManager;
use \OCP\IL10N;
use \OCP\IConfig;
use \OCP\IUser;


/**
 * Class SettingsController
 *
 * @package OCA\UserCAS\Controller
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4
 */
class SettingsController extends Controller
{
    private $l10n;
    private $config;

    private $params = array('cas_server_version', 'cas_server_hostname', 'cas_server_port', 'cas_server_path', 'cas_force_login', 'cas_autocreate',
        'cas_update_user_data', 'cas_protected_groups', 'cas_default_group', 'cas_email_mapping', 'cas_displayName_mapping', 'cas_group_mapping',
        'cas_cert_path', 'cas_debug_file', 'cas_php_cas_path', 'cas_link_to_ldap_backend', 'cas_disable_logout', 'casServiceUrl');

    protected $appName;

    public function __construct($appName, IRequest $request, IConfig $config, IL10N $l10n)
    {
        $this->config = $config;
        $this->appName = $appName;
        $this->l10n = $l10n;
        parent::__construct($appName, $request);
    }

    /**
     * @AdminRequired
     *
     * @param $cas_server_version
     * @param $cas_server_hostname
     * @param $cas_server_port
     * @param $cas_server_path
     * @param $cas_update_user_data
     * @param $cas_protected_groups
     * @param $cas_default_group
     * @param $cas_email_mapping
     * @param $cas_displayName_mapping
     * @param $cas_group_mapping
     * @param $cas_cert_path
     * @param $cas_debug_file
     * @param $cas_php_cas_path
     * @param $cas_service_url
     * @param null $cas_force_login
     * @param null $cas_autocreate
     * @param null $cas_update_user_data
     * @param null $cas_link_to_ldap_backend
     * @param null $cas_disable_logout
     * @return DataResponse
     */
    public function saveSettings($cas_server_version, $cas_server_hostname, $cas_server_port, $cas_server_path, $cas_protected_groups, $cas_default_group,
                                 $cas_email_mapping, $cas_displayName_mapping, $cas_group_mapping, $cas_cert_path, $cas_debug_file, $cas_php_cas_path, $cas_service_url,
                                 $cas_force_login = NULL, $cas_autocreate = NULL, $cas_update_user_data = NULL, $cas_link_to_ldap_backend = NULL, $cas_disable_logout = NULL)
    {

        try {

            $this->config->setAppValue($this->appName, 'cas_server_version', $cas_server_version);
            $this->config->setAppValue($this->appName, 'cas_server_hostname', $cas_server_hostname);
            $this->config->setAppValue($this->appName, 'cas_server_port', $cas_server_port);
            $this->config->setAppValue($this->appName, 'cas_server_path', $cas_server_path);


            $this->config->setAppValue($this->appName, 'cas_protected_groups', $cas_protected_groups);
            $this->config->setAppValue($this->appName, 'cas_default_group', $cas_default_group);
            $this->config->setAppValue($this->appName, 'cas_email_mapping', $cas_email_mapping);
            $this->config->setAppValue($this->appName, 'cas_displayName_mapping', $cas_displayName_mapping);
            $this->config->setAppValue($this->appName, 'cas_group_mapping', $cas_group_mapping);
            $this->config->setAppValue($this->appName, 'cas_cert_path', $cas_cert_path);
            $this->config->setAppValue($this->appName, 'cas_debug_file', $cas_debug_file);
            $this->config->setAppValue($this->appName, 'cas_php_cas_path', $cas_php_cas_path);

            $this->config->setAppValue($this->appName, 'casServiceUrl', $cas_service_url);

            $this->config->setAppValue($this->appName, 'cas_force_login', ($cas_force_login !== NULL) ? 'on' : 'off');
            $this->config->setAppValue($this->appName, 'cas_autocreate', ($cas_autocreate !== NULL) ? 'on' : 'off');
            $this->config->setAppValue($this->appName, 'cas_update_user_data', ($cas_update_user_data !== NULL) ? 'on' : 'off');
            $this->config->setAppValue($this->appName, 'cas_link_to_ldap_backend', ($cas_link_to_ldap_backend !== NULL) ? 'on' : 'off');
            $this->config->setAppValue($this->appName, 'cas_disable_logout', ($cas_disable_logout !== NULL) ? 'on' : 'off');


            return array(
                'code' => 200,
                'message' => 'Your settings have been updated.'
            );
        } catch (\Exception $e) {

            return array(
                'code' => 500,
                'message' => 'Your settings could not be updated. Please try again.'
            );
        }

        /*if (($allowed_domains === '') || ($allowed_domains === NULL)) {
            $this->config->deleteAppValue($this->appName, 'allowed_domains');
        } else {
            $this->config->setAppValue($this->appName, 'allowed_domains', $allowed_domains);
        }
        $groups = $this->groupmanager->search('');
        $group_id_list = array();
        foreach ($groups as $group) {
            $group_id_list[] = $group->getGid();
        }
        if ($registered_user_group === 'none') {
            $this->config->deleteAppValue($this->appName, 'registered_user_group');
            return new DataResponse(array(
                'data' => array(
                    'message' => (string)$this->l10n->t('Your settings have been updated.'),
                ),
            ));
        } else if (in_array($registered_user_group, $group_id_list)) {
            $this->config->setAppValue($this->appName, 'registered_user_group', $registered_user_group);
            return new DataResponse(array(
                'data' => array(
                    'message' => (string)$this->l10n->t('Your settings have been updated.'),
                ),
            ));
        } else {
            return new DataResponse(array(
                'data' => array(
                    'message' => (string)$this->l10n->t('No such group'),
                ),
            ), Http::STATUS_NOT_FOUND);
        }*/
    }

    /**
     * @AdminRequired
     *
     * @return \OCP\AppFramework\Http\TemplateResponse
     */
    public function displayPanel()
    {

        $templateParams = array();

        foreach ($this->params as $param) {

            $value = htmlentities($this->config->getAppValue($this->appName, $param));
            $templateParams[$param] = $value;
        }

        $tmpl = new TemplateResponse($this->appName, 'admin', $templateParams, 'blank');

        return $tmpl->render();
    }
}