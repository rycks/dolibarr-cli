<?php

declare(strict_types=1);

namespace Dolifleet\Console\Tasks;

/**
 * The dolifleet commands behind the screens of the console. Every screen shows the command
 * that produces it, and the console runs exactly that command (with --json): one place builds
 * both, so what is shown is what runs.
 */
final class Command
{
    /**
     * @return list<string>
     */
    public static function status(string $target): array
    {
        return ['status', $target];
    }

    /**
     * @param list<string> $words dolicli command and its arguments
     * @return list<string>
     */
    public static function exec(string $target, array $words): array
    {
        return array_merge(['exec', $target, '--'], $words);
    }

    /**
     * @return list<string>
     */
    public static function maintenance(bool $on, string $target): array
    {
        return ['maintenance', $on ? 'on' : 'off', $target];
    }

    /**
     * @return list<string>
     */
    public static function backup(string $target): array
    {
        return ['backup', $target, '--database', '--documents'];
    }

    /**
     * @return list<string>
     */
    public static function jobs(string $instance, string $job = ''): array
    {
        return '' === $job ? ['jobs', $instance] : ['jobs', $instance, '--id='.$job];
    }

    /**
     * @return list<string>
     */
    public static function log(string $instance): array
    {
        return ['log', $instance];
    }

    /**
     * @return list<string>
     */
    public static function upgradePlan(string $instance, string $version): array
    {
        return ['upgrade', 'plan', $instance, '--to='.$version];
    }

    /**
     * @return list<string>
     */
    public static function upgradeStatus(string $instance): array
    {
        return ['upgrade', 'status', $instance];
    }

    /**
     * Arguments actually run: --json right after the command name (before -- for exec), and
     * the options of a front end (confirmation answer, dry run) at the same place.
     *
     * @param list<string> $args
     * @param list<string> $options
     * @return list<string>
     */
    public static function withOptions(array $args, array $options): array
    {
        $separator = array_search('--', $args, true);
        if (false === $separator) {
            return array_merge($args, $options);
        }

        return array_merge(array_slice($args, 0, $separator), $options, array_slice($args, $separator));
    }

    /**
     * @param list<string> $args
     */
    public static function line(array $args): string
    {
        $words = ['dolifleet'];
        foreach ($args as $arg) {
            $words[] = 1 === preg_match('#^[A-Za-z0-9_./:=,@%+-]+$#', $arg) ? $arg : escapeshellarg($arg);
        }

        return implode(' ', $words);
    }
}
