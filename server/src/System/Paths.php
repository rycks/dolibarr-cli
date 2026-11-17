<?php

namespace Dolicli\System;

/**
 * System locations of the installed mode. Each one can be moved by an environment
 * variable, for tests and for servers with another layout.
 */
final class Paths
{
    public static function etcDir(): string
    {
        return self::env('DOLICLI_ETC_DIR', '/etc/dolicli');
    }

    public static function instancesIni(): string
    {
        return self::etcDir() . '/instances.ini';
    }

    public static function thresholdsIni(): string
    {
        return self::etcDir() . '/thresholds.ini';
    }

    public static function stateDir(): string
    {
        return self::env('DOLICLI_STATE_DIR', '/var/lib/dolicli');
    }

    public static function cronFile(): string
    {
        return self::env('DOLICLI_CRON_FILE', '/etc/cron.d/dolicli');
    }

    public static function prefix(): string
    {
        return self::env('DOLICLI_PREFIX', '/usr/local');
    }

    public static function installedLauncher(): string
    {
        return self::prefix() . '/bin/dolicli';
    }

    public static function installedBody(): string
    {
        return self::prefix() . '/lib/dolicli/dolicli.php';
    }

    /**
     * State file of an instance: /var/lib/dolicli/<run_as>/<instance>.json.
     */
    public static function stateFile(string $runAs, string $instanceId): string
    {
        return self::stateDir() . '/' . $runAs . '/' . $instanceId . '.json';
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? rtrim($value, '/') : $default;
    }
}
