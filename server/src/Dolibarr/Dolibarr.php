<?php

namespace Dolicli\Dolibarr;

use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\System\Log;
use User;

/**
 * Access to the loaded Dolibarr, compatible from Dolibarr 10 to the latest version.
 */
final class Dolibarr
{
    /**
     * Load a file of the Dolibarr core, path relative to htdocs (core/lib/admin.lib.php...).
     */
    public static function requireCore(string $relativePath)
    {
        require_once DOL_DOCUMENT_ROOT . '/' . ltrim($relativePath, '/');
    }

    /**
     * Value of a Dolibarr constant ($conf->global), without getDolGlobalString() (absent before 14).
     */
    public static function conf(string $name, string $default = ''): string
    {
        global $conf;

        if (!isset($conf->global->{$name})) {
            return $default;
        }
        $value = $conf->global->{$name};

        return '' === $value ? $default : (string) $value;
    }

    public static function db(): \DoliDB
    {
        global $db;

        return $db;
    }

    public static function prefix(): string
    {
        return MAIN_DB_PREFIX;
    }

    public static function version(): string
    {
        return defined('DOL_VERSION') ? DOL_VERSION : '';
    }

    /**
     * Version of the database: MAIN_VERSION_LAST_UPGRADE, else MAIN_VERSION_LAST_INSTALL.
     */
    public static function databaseVersion(): string
    {
        $version = self::conf('MAIN_VERSION_LAST_UPGRADE');

        return '' !== $version ? $version : self::conf('MAIN_VERSION_LAST_INSTALL');
    }

    /**
     * First active admin, super admins (entity 0) first. Empty string when none.
     */
    public static function firstSuperAdminLogin(): string
    {
        $db = self::db();
        $sql = 'SELECT login FROM ' . MAIN_DB_PREFIX . 'user WHERE admin = 1 AND statut = 1 ORDER BY entity ASC, rowid ASC' . $db->plimit(1);
        $resql = $db->query($sql);
        if (!$resql) {
            Log::error('first super admin: ' . $db->lasterror());

            return '';
        }
        $obj = $db->fetch_object($resql);
        if (!$obj) {
            Log::warning('no active admin user');

            return '';
        }

        return (string) $obj->login;
    }

    /**
     * Load a user by login, with rights. Failure when not found.
     */
    public static function user(string $login): User
    {
        self::requireCore('user/class/user.class.php');
        $user = new User(self::db());
        $result = $user->fetch(0, $login);
        if ($result < 0) {
            Log::error("cannot fetch user {$login}: " . $user->error);
            throw Failure::operation('USER_FETCH_FAILED', "Cannot read user '{$login}': " . $user->error);
        }
        if (0 === $result || empty($user->id)) {
            throw Failure::operation('USER_NOT_FOUND', "User '{$login}' not found.");
        }

        return $user;
    }

    /**
     * Dolibarr user acting for the command: --as, else dolibarr_user of the instance,
     * else the first super admin (with a warning). Also set as the global $user.
     */
    public static function actingUser(Context $ctx): User
    {
        global $user;

        if (isset($user) && $user instanceof User && $user->id > 0 && $ctx->actingUser() === $user->login) {
            return $user;
        }

        $login = (string) $ctx->value('as');
        if ('' === $login && null !== $ctx->instanceIfResolved()) {
            $login = $ctx->instance()->setting('dolibarr_user');
        }
        if ('' === $login) {
            $login = self::firstSuperAdminLogin();
            if ('' === $login) {
                throw Failure::operation('NO_ADMIN_USER', 'No active super admin user found: give one with --as=<login>.');
            }
            $ctx->warn('ACTING_AS_FIRST_ADMIN', "Acting as '{$login}', the first super admin: set --as or dolibarr_user to choose a dedicated technical user.");
        }

        $acting = self::user($login);
        if (empty($acting->statut) && empty($acting->status)) {
            throw Failure::operation('USER_DISABLED', "User '{$login}' is disabled and cannot act.");
        }
        if (method_exists($acting, 'loadRights')) {
            $acting->loadRights();
        } else {
            $acting->getrights();
        }
        $user = $acting;
        $ctx->setActingUser($login);

        return $acting;
    }
}
