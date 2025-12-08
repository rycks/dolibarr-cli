<?php

declare(strict_types=1);

namespace App\Util;

class DolibarrHelper
{
    /**
     * Find the first active super admin user in the database
     *
     * @return string|null Login of the first super admin, or null if none found
     */
    public static function findFirstSuperAdmin(): ?string
    {
        global $db;

        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";

        // Find first active super admin user (admin=1 and statut=1)
        $sql = "SELECT login FROM " . MAIN_DB_PREFIX . "user";
        $sql .= " WHERE admin = 1 AND statut = 1";
        $sql .= " ORDER BY rowid ASC";
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj) {
                return $obj->login;
            }
        }

        return null;
    }

    /**
     * Get a User object for the first super admin
     * Useful for operations that require a user context
     *
     * @return \User|null User object or null if no super admin found
     */
    public static function getFirstSuperAdminUser(): ?\User
    {
        global $db;

        $login = self::findFirstSuperAdmin();
        if (!$login) {
            return null;
        }

        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";
        $user = new \User($db);
        $result = $user->fetch('', $login);

        return ($result > 0) ? $user : null;
    }
}
