<?php
/**
 * ownCloud - user_cas
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 */

return ['routes' => [
    array('name' => 'settings#admin', 'url' => '/settings', 'verb' => 'POST'),
    array('name' => 'authentication#login', 'url' => '/login/cas', 'verb' => 'POST')
]];