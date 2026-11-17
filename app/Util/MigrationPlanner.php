<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Database migration steps, computed like Dolibarr's install/check.php.
 *
 * install/upgrade.php only runs the SQL files x.y.0-* and *-to.sql for the
 * versions it receives, so a jump over several major versions must be split
 * into one call per migration file, in order.
 */
final class MigrationPlanner
{
    /**
     * List the migration files x.y.z-a.b.c.sql found in a directory.
     *
     * @return array<int, string>
     */
    public static function migrationFiles(string $dir): array
    {
        $files = [];
        foreach ((array) glob(rtrim($dir, '/').'/*.sql') as $path) {
            $files[] = basename((string) $path);
        }

        return $files;
    }

    /**
     * @param  array<int, string>  $files  Migration file names (x.y.z-a.b.c.sql)
     * @param  string  $lastUpgrade  Version the database was last migrated to
     * @param  string  $target  Version of the installed files
     * @param  bool  $force  Run the step of the current branch again even if the database is up to date
     * @return array<int, array{from: string, to: string}>
     */
    public static function plan(array $files, string $lastUpgrade, string $target, bool $force = false): array
    {
        $target = self::clean($target);
        $lastUpgrade = self::clean($lastUpgrade);

        $steps = [];
        foreach ($files as $file) {
            if (1 === preg_match('/^(\d+\.\d+\.\d+)-(\d+\.\d+\.\d+)\.sql$/', $file, $m) && version_compare($target, $m[2]) >= 0) {
                $steps[] = ['from' => $m[1], 'to' => $m[2]];
            }
        }
        usort($steps, static fn (array $a, array $b): int => version_compare($a['from'], $b['from']));

        if ( ! $force && version_compare($lastUpgrade, $target) >= 0) {
            return [];
        }

        // First step to run: the first one going beyond the last upgrade, or the one of the
        // target branch (x.y.0 -> target x.y.z: only the patch number differs).
        foreach ($steps as $index => $step) {
            if (version_compare($step['to'], $lastUpgrade) > 0 || self::sameBranch($step['to'], $target)) {
                return array_slice($steps, $index);
            }
        }

        return [];
    }

    /**
     * Drop any suffix (-beta, -rc1...) from a version.
     */
    public static function clean(string $version): string
    {
        return (string) preg_replace('/-.*$/', '', trim($version));
    }

    public static function isVersion(string $version): bool
    {
        return 1 === preg_match('/^\d+\.\d+\.\d+$/', self::clean($version));
    }

    public static function sameBranch(string $a, string $b): bool
    {
        $pa = explode('.', self::clean($a));
        $pb = explode('.', self::clean($b));

        return ($pa[0] ?? '') === ($pb[0] ?? '') && ($pa[1] ?? '') === ($pb[1] ?? '');
    }
}
