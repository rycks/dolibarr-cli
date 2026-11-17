<?php

namespace Dolicli\System;

/**
 * Server log: dol_syslog() once Dolibarr is loaded, the system syslog otherwise.
 * Every failure path logs its cause here before returning.
 */
final class Log
{
    /** @var bool */
    private static $opened = false;

    /** @var array<int, string> */
    private static $memory = [];

    public static function error(string $message)
    {
        self::write($message, LOG_ERR);
    }

    public static function warning(string $message)
    {
        self::write($message, LOG_WARNING);
    }

    public static function info(string $message)
    {
        self::write($message, LOG_INFO);
    }

    public static function write(string $message, int $level)
    {
        $message = str_replace(["\r", "\n"], ' ', $message);
        self::$memory[] = $message;
        if (count(self::$memory) > 100) {
            array_shift(self::$memory);
        }

        if (function_exists('dol_syslog') && defined('DOL_DOCUMENT_ROOT')) {
            dol_syslog('dolicli: ' . $message, $level);

            return;
        }
        if (!self::$opened) {
            self::$opened = @openlog('dolicli', LOG_PID, LOG_USER);
        }
        @syslog($level, $message);
    }

    /**
     * Last messages written during this run (used by tests and error reports).
     *
     * @return array<int, string>
     */
    public static function recent(): array
    {
        return self::$memory;
    }
}
