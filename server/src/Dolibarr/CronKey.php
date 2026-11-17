<?php

namespace Dolicli\Dolibarr;

use Dolicli\Cli\Failure;

/**
 * CRON_KEY: the securitykey parameter of the URL that triggers the scheduled jobs over HTTP.
 * Stored in llx_const for the current entity, encrypted by recent versions.
 */
final class CronKey
{
    /**
     * Current value, empty string when not set.
     */
    public static function get(): string
    {
        global $conf;

        $db = Dolibarr::db();
        $sql = 'SELECT value FROM ' . MAIN_DB_PREFIX . "const WHERE name = 'CRON_KEY' AND entity = " . ((int) $conf->entity);
        $resql = $db->query($sql);
        if (!$resql) {
            throw Failure::operation('DATABASE_ERROR', 'Database query failed: ' . $db->lasterror());
        }
        $obj = $db->fetch_object($resql);
        $value = $obj ? (string) $obj->value : '';
        // Recent versions store this constant encrypted (dolibarr_set_const encrypts *_KEY names).
        if ('' !== $value && function_exists('dolDecrypt')) {
            $decoded = dolDecrypt($value);
            if (is_string($decoded) && '' !== $decoded) {
                $value = $decoded;
            }
        }

        return $value;
    }

    public static function set(string $value, string $note)
    {
        global $conf;

        Dolibarr::requireCore('core/lib/admin.lib.php');
        $db = Dolibarr::db();
        if (dolibarr_set_const($db, 'CRON_KEY', $value, 'chaine', 0, $note, (int) $conf->entity) <= 0) {
            throw Failure::operation('CRON_KEY_UPDATE_FAILED', 'Failed to update CRON_KEY: ' . $db->lasterror());
        }
    }

    public static function clear()
    {
        global $conf;

        Dolibarr::requireCore('core/lib/admin.lib.php');
        $db = Dolibarr::db();
        if (dolibarr_del_const($db, 'CRON_KEY', (int) $conf->entity) < 0) {
            throw Failure::operation('CRON_KEY_UPDATE_FAILED', 'Failed to remove CRON_KEY: ' . $db->lasterror());
        }
    }

    /**
     * Path of the HTTP trigger: cron_run_jobs_by_url.php in recent versions, cron_run_jobs.php before.
     */
    public static function urlPath(): string
    {
        $byUrl = 'public/cron/cron_run_jobs_by_url.php';
        if (!is_file(DOL_DOCUMENT_ROOT . '/' . $byUrl) && is_file(DOL_DOCUMENT_ROOT . '/public/cron/cron_run_jobs.php')) {
            return 'public/cron/cron_run_jobs.php';
        }

        return $byUrl;
    }
}
