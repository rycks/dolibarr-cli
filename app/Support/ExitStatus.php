<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Process exit code shared between the commands and the entry point.
 *
 * Minicli controllers return void, so a command records its outcome here and
 * the `dolibarr` entry point exits with it once the command is done.
 */
final class ExitStatus
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    private static int $code = self::SUCCESS;

    public static function set(int $code): void
    {
        self::$code = $code;
    }

    public static function get(): int
    {
        return self::$code;
    }

    public static function reset(): void
    {
        self::$code = self::SUCCESS;
    }
}
