<?php
/**
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 */
script('user_cas', 'settings');
style('user_cas', 'settings');
?>

<form id="user_cas" class='section' method="post">
    <h2><?php p($l->t('CAS Authentication backend')); ?>
        &nbsp;<?php p(\OCP\App::getAppInfo('user_cas')["version"]); ?></h2>

    <div id="casSettings" class="personalblock">
        <ul>
            <li><a href="#casSettings-1"><?php p($l->t('CAS Server')); ?></a></li>
            <li><a href="#casSettings-2"><?php p($l->t('Basic')); ?></a></li>
            <li><a href="#casSettings-3"><?php p($l->t('Mapping')); ?></a></li>
            <li><a href="#casSettings-4"><?php p($l->t('Groups')); ?></a></li>
            <li><a href="#casSettings-5"><?php p($l->t('ECAS Settings')); ?></a></li>
            <li><a href="#casSettings-6"><?php p($l->t('phpCAS Library')); ?></a></li>
        </ul>
        <!-- CAS Server Settings -->
        <fieldset id="casSettings-1">
            <p><label for="cas_server_version"><?php p($l->t('CAS Server Version')); ?></label>
                <select id="cas_server_version" name="cas_server_version">
                    <?php $version = $_['cas_server_version']; ?>
                    <option value="2.0" <?php echo $version === '2.0' ? 'selected' : ''; ?>>CAS 2.0</option>
                    <option value="1.0" <?php echo $version === '1.0' ? 'selected' : ''; ?>>CAS 1.0</option>
                    <option value="S1" <?php echo $version === 'S1' ? 'selected' : ''; ?>>SAML 1.1</option>
                </select>
            </p>
            <p><label for="cas_server_hostname"><?php p($l->t('CAS Server Hostname')); ?></label><input
                        id="cas_server_hostname"
                        name="cas_server_hostname"
                        value="<?php p($_['cas_server_hostname']); ?>">
            </p>
            <p><label for="cas_server_port"><?php p($l->t('CAS Server Port')); ?></label><input
                        id="cas_server_port"
                        name="cas_server_port"
                        placeholder="443"
                        value="<?php p($_['cas_server_port']); ?>">
            </p>
            <p><label for="cas_server_path"><?php p($l->t('CAS Server Path')); ?></label><input
                        id="cas_server_path"
                        name="cas_server_path"
                        placeholder="/cas"
                        value="<?php p($_['cas_server_path']); ?>">
            </p>
            <p><label for="cas_service_url"><?php p($l->t('Service URL')); ?></label><input
                        id="cas_service_url"
                        name="cas_service_url"
                        value="<?php p($_['cas_service_url']); ?>">
            </p>
            <p><label
                        for="cas_cert_path"><?php p($l->t('Certification file path (.crt).')); ?></label><input
                        id="cas_cert_path" name="cas_cert_path" value="<?php p($_['cas_cert_path']); ?>"> <span
                        class="csh"><?php p($l->t('Leave empty if you don’t want to validate your CAS server instance')); ?></span>
            </p>
        </fieldset>
        <!-- Basic Settings -->
        <fieldset id="casSettings-2">
            <p><input type="checkbox" id="cas_force_login"
                      name="cas_force_login" <?php print_unescaped((($_['cas_force_login'] === 'true' || $_['cas_force_login'] === 'on' || $_['cas_force_login'] === '1') ? 'checked="checked"' : '')); ?>>
                <label class='checkbox'
                       for="cas_force_login"><?php p($l->t('Force user login using CAS?')); ?></label>
            </p>
            <p>
                <label for="cas_force_login_exceptions"><?php p($l->t('Don’t use force login on these client-IPs')); ?></label><input
                        id="cas_force_login_exceptions"
                        name="cas_force_login_exceptions"
                        value="<?php p($_['cas_force_login_exceptions']); ?>"
                    <?php print_unescaped((($_['cas_force_login'] === 'false' || $_['cas_force_login'] === 'off' || $_['cas_force_login'] === '0') ? 'disabled="disabled"' : '')); ?> />
                <span class="csh"><?php p($l->t('Comma separated list of client IP addresses (or address ranges), which won’t be forced to login if "Force user login" is enabled (e.g. 192.168.1.1/254,192.168.2.5)')) ?></span>
            </p>
            <p><input type="checkbox" id="cas_disable_logout"
                      name="cas_disable_logout" <?php print_unescaped((($_['cas_disable_logout'] === 'true' || $_['cas_disable_logout'] === 'on' || $_['cas_disable_logout'] === '1') ? 'checked="checked"' : ''));
                print_unescaped((($_['cas_force_login'] === 'true' || $_['cas_force_login'] === 'on' || $_['cas_force_login'] === '1') ? 'disabled="disabled"' : '')); ?>>
                <label class='checkbox'
                       for="cas_disable_logout"><?php p($l->t('Disable CAS logout (do only OwnCloud logout)')); ?></label>
            </p>
            <p><input type="checkbox" id="cas_autocreate"
                      name="cas_autocreate" <?php print_unescaped((($_['cas_autocreate'] === 'true' || $_['cas_autocreate'] === 'on' || $_['cas_autocreate'] === '1') ? 'checked="checked"' : '')); ?>>
                <label class='checkbox'
                       for="cas_autocreate"><?php p($l->t('Autocreate user after first CAS login?')); ?></label>
            </p>

            <p><input type="checkbox" id="cas_update_user_data"
                      name="cas_update_user_data" <?php print_unescaped((($_['cas_update_user_data'] === 'true' || $_['cas_update_user_data'] === 'on' || $_['cas_update_user_data'] === '1') ? 'checked="checked"' : '')); ?>>
                <label class='checkbox'
                       for="cas_update_user_data"><?php p($l->t('Update user data after each CAS login?')); ?></label>
            </p>
            <p>
                <label for="cas_handlelogoutrequest_servers"><?php p($l->t('Logout Servers')); ?></label><input
                        id="cas_handlelogout_servers"
                        name="cas_handlelogout_servers"
                        value="<?php p($_['cas_handlelogout_servers']); ?>"
                    <?php print_unescaped((($_['cas_disable_logout'] === 'true' || $_['cas_disable_logout'] === 'on' || $_['cas_disable_logout'] === '1') ? 'disabled="disabled"' : '')); ?> />
                <span class="csh"><?php p($l->t('Comma separated list of servers which can send logout requests (leave empty if you do not want to restrict logout to defined servers)')) ?></span>
            </p>

            <!-- <p><input type="checkbox" id="cas_link_to_ldap_backend"
                      name="cas_link_to_ldap_backend" <?php /*print_unescaped((($_['cas_link_to_ldap_backend'] === 'true' || $_['cas_link_to_ldap_backend'] === 'on' || $_['cas_link_to_ldap_backend'] === '1') ? 'checked="checked"' : ''));*/ ?>>
                <label class='checkbox'
                       for="cas_link_to_ldap_backend"><?php p($l->t('Link CAS authentication with LDAP users and groups backend')); ?></label>
            </p> -->

        </fieldset>
        <!-- Mapping Settings -->
        <fieldset id="casSettings-3">
            <p><label for="cas_email_mapping"><?php p($l->t('Email')); ?></label><input
                        id="cas_email_mapping"
                        name="cas_email_mapping"
                        value="<?php p($_['cas_email_mapping']); ?>"/>
            </p>
            <p><label for="cas_displayName_mapping"><?php p($l->t('Display Name')); ?></label><input
                        id="cas_displayName_mapping"
                        name="cas_displayName_mapping"
                        value="<?php p($_['cas_displayName_mapping']); ?>"/>
            </p>
            <p><label for="cas_group_mapping"><?php p($l->t('Groups')); ?></label><input
                        id="cas_group_mapping"
                        name="cas_group_mapping"
                        value="<?php p($_['cas_group_mapping']); ?>"/>
            </p>
        </fieldset>
        <!-- Groups -->
        <fieldset id="casSettings-4">
            <p><label
                        for="cas_protected_groups"><?php p($l->t('Locked Groups')); ?></label><input
                        id="cas_protected_groups" name="cas_protected_groups"
                        value="<?php p($_['cas_protected_groups']); ?>"
                        placeholder="group1,group2,group3"
                        title="<?php p($l->t('Multivalued field, use comma to separate values')); ?>"/> <span class="csh"><?php p($l->t('Groups that will not be unlinked from the user when sync the CAS server and the owncloud')); ?></span></p>
            <p><label
                        for="cas_default_group"><?php p($l->t('Default Group')); ?></label><input
                        id="cas_default_group" name="cas_default_group"
                        placeholder="defaultGroup"
                        value="<?php p($_['cas_default_group']); ?>"> <span class="csh"><?php p($l->t('Default group when autocreating users and no group data was found for the user')); ?></span></p>
            <p><label
                        for="cas_access_allow_groups"><?php p($l->t('Authorized CAS Groups')); ?></label><input
                        id="cas_access_allow_groups" name="cas_access_allow_groups"
                        value="<?php p($_['cas_access_allow_groups']); ?>"
                        placeholder="group1,group2,group3"
                        title="<?php p($l->t('Multivalued field, use comma to separate values')); ?>"/> <span class="csh"><?php p($l->t('Users in the following groups will be able to log into ownCloud, users not in one of the groups will be logged out immediately')); ?></span></p>
            <p><label
                        for="cas_access_group_quotas"><?php p($l->t('Group Quotas')); ?></label><input
                        id="cas_access_group_quotas" name="cas_access_group_quotas"
                        value="<?php p($_['cas_access_group_quotas']); ?>"
                        placeholder="group1:5GB,group2:20GB,group3:none"
                        title="<?php p($l->t('Multivalued field, use comma to separate values')); ?>"/></p>
        </fieldset>
        <!-- ECAS Settings -->
        <fieldset id="casSettings-5">
            <p><input type="checkbox" id="cas_ecas_attributeparserenabled"
                      name="cas_ecas_attributeparserenabled" <?php print_unescaped((($_['cas_ecas_attributeparserenabled'] === 'true' || $_['cas_ecas_attributeparserenabled'] === 'on' || $_['cas_ecas_attributeparserenabled'] === '1') ? 'checked="checked"' : '')); ?>>
                <label class='checkbox'
                       for="cas_ecas_attributeparserenabled"><?php p($l->t('Use ECAS Attribute Parser?')); ?></label>
            </p>
            <p><input type="checkbox" id="cas_ecas_request_full_userdetails"
                      name="cas_ecas_request_full_userdetails" <?php print_unescaped((($_['cas_ecas_request_full_userdetails'] === 'true' || $_['cas_ecas_request_full_userdetails'] === 'on' || $_['cas_ecas_request_full_userdetails'] === '1') ? 'checked="checked"' : '')); ?>>
                <label class='checkbox'
                       for="cas_ecas_request_full_userdetails"><?php p($l->t('Request full user details?')); ?></label>
            </p>
            <p><label for="cas_ecas_accepted_strengths"><?php p($l->t('ECAS Strength')); ?></label>
                <select id="cas_ecas_accepted_strengths" name="cas_ecas_accepted_strengths">
                    <?php $strength = $_['cas_ecas_accepted_strengths']; ?>
                    <option value="" <?php echo $strength === '' ? 'selected' : ''; ?>><?php p($l->t('Not set')); ?></option>
                    <option value="BASIC" <?php echo $strength === 'BASIC' ? 'selected' : ''; ?>>BASIC</option>
                    <option value="MEDIUM" <?php echo $strength === 'MEDIUM' ? 'selected' : ''; ?>>MEDIUM</option>
                    <option value="HIGH" <?php echo $strength === 'HIGH' ? 'selected' : ''; ?>>HIGH</option>
                </select>
            </p>
            <p><label for="cas_ecas_assurance_level"><?php p($l->t('ECAS AssuranceLevel')); ?></label>
                <select id="cas_ecas_assurance_level" name="cas_ecas_assurance_level">
                    <?php $assuranceLevel = $_['cas_ecas_assurance_level']; ?>
                    <option value="" <?php echo $assuranceLevel === '' ? 'selected' : ''; ?>><?php p($l->t('Not set')); ?></option>
                    <option value="LOW" <?php echo $assuranceLevel === 'LOW' ? 'selected' : ''; ?>>LOW</option>
                    <option value="MEDIUM" <?php echo $assuranceLevel === 'MEDIUM' ? 'selected' : ''; ?>>MEDIUM</option>
                    <option value="HIGH" <?php echo $assuranceLevel === 'HIGH' ? 'selected' : ''; ?>>HIGH</option>
                    <option value="TOP" <?php echo $assuranceLevel === 'TOP' ? 'selected' : ''; ?>>TOP</option>
                </select>
            </p>
            <p>
                <label for="cas_ecas_retrieve_groups"
                       title=""><?php p($l->t('Query ECAS groups')); ?></label><input
                        id="cas_ecas_retrieve_groups" name="cas_ecas_retrieve_groups" placeholder="*"
                        value="<?php p($_['cas_ecas_retrieve_groups']); ?>"> <span class="csh"><?php p($l->t('Note down all groups which you want to receive from your ECAS instance, * returns all groups')); ?></span></p>
        </fieldset>
        <!-- phpCAS Settings -->
        <fieldset id="casSettings-6">
            <p>
                <label for="cas_php_cas_path"><?php p($l->t('Overwrite phpCAS path (CAS.php file)')); ?></label><input
                        id="cas_php_cas_path"
                        name="cas_php_cas_path"
                        value="<?php p($_['cas_php_cas_path']); ?>"/> <span class="csh"><?php p($l->t('Optional: Overwrite phpCAS path (CAS.php file) if you want to use your own version. Leave blank to use the shipped version.')); ?></span>
            </p>
            <p><label for="cas_debug_file"><?php p($l->t('PHP CAS debug file')); ?></label><input
                        id="cas_debug_file"
                        name="cas_debug_file"
                        value="<?php p($_['cas_debug_file']); ?>"/>
            </p>
        </fieldset>
        <input type="hidden" value="<?php p($_['requesttoken']); ?>" name="requesttoken"/>
        <input id="casSettingsSubmit" type="submit" value="<?php p($l->t('Save')); ?>"/>
    </div>
</form>