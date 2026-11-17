<?php

declare(strict_types=1);

namespace App\Util;

use User;

class DolibarrHelper
{
    /**
     * Write a message in the Dolibarr log (syslog module), prefixed with dolicli.
     */
    public static function log(string $message, int $level = LOG_INFO): void
    {
        if (function_exists('dol_syslog')) {
            dol_syslog('dolicli: '.$message, $level);
        }
    }

    /**
     * Find the first active admin user, super admins (entity 0) first.
     *
     * @return string|null Login of the user, or null if none found
     */
    public static function findFirstSuperAdmin(): ?string
    {
        global $db;

        $sql = 'SELECT login FROM '.MAIN_DB_PREFIX.'user';
        $sql .= ' WHERE admin = 1 AND statut = 1';
        $sql .= ' ORDER BY entity ASC, rowid ASC';
        $sql .= $db->plimit(1);

        $resql = $db->query($sql);
        if ( ! $resql) {
            self::log('findFirstSuperAdmin: '.$db->lasterror(), LOG_ERR);

            return null;
        }

        $obj = $db->fetch_object($resql);
        if ( ! $obj) {
            self::log('findFirstSuperAdmin: no active admin user', LOG_WARNING);

            return null;
        }

        return $obj->login;
    }

    /**
     * Get a User object for the first super admin, with its rights loaded.
     * Useful for operations that require a user context.
     */
    public static function getFirstSuperAdminUser(): ?User
    {
        global $db;

        $login = self::findFirstSuperAdmin();
        if ( ! $login) {
            return null;
        }

        require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
        $user = new User($db);
        if ($user->fetch(0, $login) <= 0) {
            self::log('getFirstSuperAdminUser: cannot fetch user '.$login.': '.$user->error, LOG_ERR);

            return null;
        }
        $user->getrights();

        return $user;
    }
}
