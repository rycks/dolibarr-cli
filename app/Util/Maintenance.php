<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Dolibarr maintenance mode (MAIN_ONLY_LOGIN_ALLOWED): only one login may connect.
 *
 * enable() remembers whether it switched the mode on, so disable() only switches
 * off what this process switched on, and can safely run twice (normal end and
 * shutdown function).
 */
final class Maintenance
{
    private bool $enabledByUs = false;

    private string $lastError = '';

    public function lastError(): string
    {
        return $this->lastError;
    }

    public static function currentLogin(): string
    {
        return DolibarrHelper::conf('MAIN_ONLY_LOGIN_ALLOWED');
    }

    /**
     * Switch maintenance on for $login (default: first super admin).
     * Returns the login allowed to connect, null on failure.
     */
    public function enable(string $note, ?string $login = null): ?string
    {
        global $db, $conf;

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        $current = self::currentLogin();
        if ('' !== $current) {
            return $current;
        }

        $login ??= DolibarrHelper::findFirstSuperAdmin();
        if (null === $login || '' === $login) {
            return $this->failWith('No active super admin user found: cannot enable maintenance mode.');
        }

        if (dolibarr_set_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $login, 'chaine', 0, $note, $conf->entity) <= 0) {
            return $this->failWith('Cannot enable maintenance mode: '.$db->lasterror());
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
        if ( ! $this->enabledByUs) {
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
        global $db, $conf;

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        $result = dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity);
        unset($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
        if ($result < 0) {
            DolibarrHelper::log('maintenance: cannot disable: '.$db->lasterror(), LOG_ERR);

            return false;
        }

        return true;
    }

    public function wasEnabledByUs(): bool
    {
        return $this->enabledByUs;
    }

    private function failWith(string $message): ?string
    {
        $this->lastError = $message;
        DolibarrHelper::log('maintenance: '.$message, LOG_ERR);

        return null;
    }
}
