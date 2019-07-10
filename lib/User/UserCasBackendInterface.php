<?php
/**
 * Created by PhpStorm.
 * User: felixrupp
 * Date: 17.09.18
 * Time: 01:51
 */

namespace OCA\UserCAS\User;

/**
 * Interface UserCasBackendInterface
 *
 * @package OCA\UserCAS\User
 *
 * @author Felix Rupp <kontakt@felixrupp.com>
 * @copyright Felix Rupp <kontakt@felixrupp.com>
 */
interface UserCasBackendInterface
{

    /**
     * @param string $loginName
     * @param string $password
     * @return string|bool The users UID or false
     */
    public function checkPassword(string $loginName, string $password);
}