<?php

namespace Dolicli\Dolibarr;

use Dolicli\System\Log;

/**
 * Dolibarr maintenance mode (MAIN_ONLY_LOGIN_ALLOWED): only one login may connect.
 * main.inc.php checks it on every request, so open sessions of other users are blocked too.
 *
 * enable() remembers whether it switched the mode on, so disable() only switches
 * off what this process switched on, and can safely run twice (normal end and cleanup).
 */
final class Maintenance
{
    /** @var bool */
    private $enabledByUs = false;

    /** @var string */
    private $lastError = '';

    public function lastError(): string
    {
        return $this->lastError;
    }

    public static function currentLogin(): string
    {
        return Dolibarr::conf('MAIN_ONLY_LOGIN_ALLOWED');
    }

    /**
     * Switch maintenance on for $login (default: maintenance_login, else first super admin).
     * Returns the login allowed to connect, empty string on failure.
     */
    public function enable(string $note, string $login = ''): string
    {
        global $conf;

        Dolibarr::requireCore('core/lib/admin.lib.php');

        $current = self::currentLogin();
        if ('' !== $current) {
            return $current;
        }

        if ('' === $login) {
            $login = Dolibarr::firstSuperAdminLogin();
        }
        if ('' === $login) {
            $this->failWith('No active super admin user found: cannot enable maintenance mode.');

            return '';
        }

        $db = Dolibarr::db();
        if (dolibarr_set_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $login, 'chaine', 0, $note, $conf->entity) <= 0) {
            $this->failWith('Cannot enable maintenance mode: ' . $db->lasterror());

            return '';
        }
        $conf->global->MAIN_ONLY_LOGIN_ALLOWED = $login;
        $this->enabledByUs = true;

        return $login;
    }

    /**
     * Switch maintenance off if this object switched it on.
     */
    public function disable(): bool
    {
        if (!$this->enabledByUs) {
            return true;
        }
        $this->enabledByUs = false;

        return self::forceDisable();
    }

    /**
     * Switch maintenance off whoever switched it on.
     */
    public static function forceDisable(): bool
    {
        global $conf;

        Dolibarr::requireCore('core/lib/admin.lib.php');
        $db = Dolibarr::db();
        $result = dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity);
        unset($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
        if ($result < 0) {
            Log::error('maintenance: cannot disable: ' . $db->lasterror());

            return false;
        }

        return true;
    }

    public function wasEnabledByUs(): bool
    {
        return $this->enabledByUs;
    }

    private function failWith(string $message)
    {
        $this->lastError = $message;
        Log::error('maintenance: ' . $message);
    }
}
