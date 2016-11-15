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

namespace OCA\UserCAS\User;


/**
 * Class Backend
 *
 * @package OCA\UserCAS\User
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 *
 * @since 1.4.0
 */
class Backend extends \OC\User\Backend
{

    /**
     * @param string|boolean $uid
     * @param string $password
     * @return bool|string
     */
    public function checkPassword($uid = FALSE, $password = NULL)
    {

        if(\phpCAS::isInitialized()) {

            if (!\phpCAS::isAuthenticated()) {

                \OCP\Util::writeLog('cas', 'phpCAS user has not been authenticated.', \OCP\Util::ERROR);
                return FALSE;
            }

            if ($uid === FALSE) {

                \OCP\Util::writeLog('cas', 'phpCAS returned no user.', \OCP\Util::ERROR);
                return FALSE;
            }

            return $uid;
        }
        else {

            \OCP\Util::writeLog('cas', 'phpCAS has not been initialized.', \OCP\Util::ERROR);
            return FALSE;
        }

    }
}