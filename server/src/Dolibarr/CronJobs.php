<?php

namespace Dolicli\Dolibarr;

/**
 * Scheduled jobs (table cronjob): definitions of a failed, late and stuck job, shared by the
 * monitor checks cron.failed, cron.stale, cron.stuck and by cron list so that they cannot drift.
 * Conditions use the bare column names of the cronjob table (no alias).
 */
final class CronJobs
{
    /** Margin before a job whose next run date is past counts as late (seconds). */
    const STALE_MARGIN = 3600;

    /** A job marked as running for longer than this is stuck (seconds). */
    const STUCK_AFTER = 21600;

    /** Values of unitfrequency (seconds) and their names. */
    const UNITS = [60 => 'minute', 3600 => 'hour', 86400 => 'day', 604800 => 'week', 2678400 => 'month'];

    /**
     * SQL condition: active job whose last run failed (lastresult is '0' after a success).
     */
    public static function failedCondition(): string
    {
        return "status = 1 AND lastresult IS NOT NULL AND lastresult <> '' AND lastresult <> '0'";
    }

    /**
     * SQL condition: active job, not running, whose next run date is past by more than STALE_MARGIN.
     */
    public static function lateCondition(int $now): string
    {
        $limit = Dolibarr::db()->idate($now - self::STALE_MARGIN);

        return "status = 1 AND processing = 0 AND datenextrun IS NOT NULL AND datenextrun < '" . $limit . "'";
    }

    /**
     * SQL condition: job marked as running for longer than STUCK_AFTER.
     */
    public static function stuckCondition(int $now): string
    {
        $limit = Dolibarr::db()->idate($now - self::STUCK_AFTER);

        return "processing = 1 AND datelastrun IS NOT NULL AND datelastrun < '" . $limit . "'";
    }

    /**
     * SQL condition: jobs of all entities (0) and of the current one.
     */
    public static function entityCondition(): string
    {
        global $conf;

        $entity = isset($conf->entity) ? (int) $conf->entity : 1;

        return 'entity IN (0, ' . $entity . ')';
    }

    /**
     * "every 1 day", "every 5 minutes"; an unusual unit is given in seconds.
     *
     * @param mixed $frequency
     * @param mixed $unit
     */
    public static function describeFrequency($frequency, $unit): string
    {
        $count = (int) $frequency;
        $seconds = (int) $unit;
        $units = self::UNITS;
        if (isset($units[$seconds])) {
            return 'every ' . $count . ' ' . $units[$seconds] . (1 === $count ? '' : 's');
        }
        $total = $count * $seconds;

        return 'every ' . $total . ' second' . (1 === $total ? '' : 's');
    }
}
