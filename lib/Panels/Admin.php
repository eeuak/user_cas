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

namespace OCA\UserCas\Panels;

use OC\Settings\Panels\Helper;
use OCP\Settings\ISettings;
use OCP\Template;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;

/**
 * Class Admin
 *
 * @package OCA\UserCas\Panels
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.5
 */
class Admin implements ISettings
{

    /**
     * @var array
     */
    private $params = array('cas_server_version', 'cas_server_hostname', 'cas_server_port', 'cas_server_path', 'cas_force_login', 'cas_autocreate',
        'cas_update_user_data', 'cas_protected_groups', 'cas_default_group', 'cas_email_mapping', 'cas_displayName_mapping', 'cas_group_mapping',
        'cas_cert_path', 'cas_debug_file', 'cas_php_cas_path', 'cas_link_to_ldap_backend', 'cas_disable_logout', 'cas_service_url');

    /**
     * @var IConfig
     */
    private $config;

    /**
     * Admin constructor.
     *
     * @param IConfig $config
     */
    public function __construct(IConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @return string
     */
    public function getSectionID()
    {
        return 'general';
    }

    /**
     * @see Nextcloud 12 support
     *
     * @return string
     *
     * @since 1.5.0
     */
    public function getSection()
    {
        return 'additional';
    }

    /**
     * @return int
     */
    public function getPriority()
    {
        return 0;
    }

    /**
     * Get Panel
     *
     * @return Template
     */
    public function getPanel()
    {

        #\OCP\Util::addscript('user_cas', 'settings');
        #\OCP\Util::addStyle('user_cas', 'settings');

        $tmpl = new Template('user_cas', 'admin');

        foreach ($this->params as $param) {

            $value = htmlentities($this->config->getAppValue('user_cas', $param));

            $tmpl->assign($param, $value);
        }

        return $tmpl;
    }

    /**
     * @see Nextcloud 12 support
     *
     * @return TemplateResponse
     *
     * @since 1.5.0
     */
    public function getForm() {

        $parameters = array();

        foreach ($this->params as $param) {

            $parameters[$param] = htmlentities($this->config->getAppValue('user_cas', $param));
        }

        return new TemplateResponse('user_cas', 'admin', $parameters);
    }
}