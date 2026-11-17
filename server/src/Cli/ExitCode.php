<?php

namespace Dolicli\Cli;

/**
 * Process exit codes of dolicli (contract 4.6 of the specification).
 */
final class ExitCode
{
    const SUCCESS = 0;
    const FAILURE = 1;
    const USAGE = 2;
    const ENVIRONMENT = 3;
    const CONFIRMATION = 4;
    const LOCKED = 5;
    const ROOT = 6;

    /**
     * Nagios plugin codes, used with --format=nagios.
     */
    const NAGIOS_OK = 0;
    const NAGIOS_WARNING = 1;
    const NAGIOS_CRITICAL = 2;
    const NAGIOS_UNKNOWN = 3;

    /**
     * @return array<int, string>
     */
    public static function descriptions(): array
    {
        return [
            self::SUCCESS => 'Success',
            self::FAILURE => 'Operation failed',
            self::USAGE => 'Invalid usage (unknown command, missing or invalid parameter)',
            self::ENVIRONMENT => 'Incompatible environment (PHP, extension, Dolibarr not found or unreadable, database unreachable)',
            self::CONFIRMATION => 'Confirmation required (no terminal and no --yes)',
            self::LOCKED => 'Lock held by another operation on this instance',
            self::ROOT => 'Running as root refused',
        ];
    }
}
