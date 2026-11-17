<?php

declare(strict_types=1);

namespace Dolifleet;

use RuntimeException;

/**
 * Local files of the pilot: inventory in XDG_CONFIG_HOME, state cache and journal in XDG_DATA_HOME.
 */
final class Paths
{
    public static function configDir(): string
    {
        $base = getenv('XDG_CONFIG_HOME');

        return (is_string($base) && '' !== $base ? rtrim($base, '/') : self::home().'/.config').'/dolifleet';
    }

    public static function dataDir(): string
    {
        $base = getenv('XDG_DATA_HOME');

        return (is_string($base) && '' !== $base ? rtrim($base, '/') : self::home().'/.local/share').'/dolifleet';
    }

    public static function inventory(): string
    {
        $file = getenv('DOLIFLEET_INVENTORY');

        return is_string($file) && '' !== $file ? $file : self::configDir().'/inventory.yaml';
    }

    public static function stateDatabase(): string
    {
        return self::dataDir().'/state.sqlite';
    }

    public static function journal(): string
    {
        return self::dataDir().'/journal.jsonl';
    }

    public static function home(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && '' !== $home) {
            return rtrim($home, '/');
        }
        $info = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;

        return is_array($info) ? $info['dir'] : '/tmp';
    }

    public static function ensure(string $dir): void
    {
        if ( ! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
    }
}
