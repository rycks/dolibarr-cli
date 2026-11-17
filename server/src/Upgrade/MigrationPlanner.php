<?php

namespace Dolicli\Upgrade;

/**
 * Database migration steps, computed like Dolibarr's install/check.php.
 *
 * The package of a version holds every migration file since 3.0.0. install/upgrade.php
 * only runs the files x.y.0-* and *-to.sql for the versions it receives, so a jump over
 * several major versions is split into one call per migration file, in order.
 */
final class MigrationPlanner
{
    /**
     * Migration files x.y.z-a.b.c.sql found in a directory.
     *
     * @return array<int, string>
     */
    public static function migrationFiles(string $dir): array
    {
        $files = [];
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return [];
        }
        foreach ($entries as $entry) {
            if ('.sql' === substr($entry, -4)) {
                $files[] = $entry;
            }
        }

        return $files;
    }

    /**
     * @param array<int, string> $files Migration file names (x.y.z-a.b.c.sql)
     * @param string $lastUpgrade Version the database was last migrated to
     * @param string $target Version of the installed files
     * @param bool $force Run the step of the current branch again even if the database is up to date
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
        usort($steps, function (array $a, array $b) {
            return version_compare($a['from'], $b['from']);
        });

        if (!$force && version_compare($lastUpgrade, $target) >= 0) {
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

        return (isset($pa[0]) ? $pa[0] : '') === (isset($pb[0]) ? $pb[0] : '')
            && (isset($pa[1]) ? $pa[1] : '') === (isset($pb[1]) ? $pb[1] : '');
    }

    /**
     * Rule of main.inc.php: the files are newer than the database (comparison up to the
     * patch level, unless MAIN_NO_UPGRADE_REDIRECT_ON_LEVEL_3_CHANGE limits it to x.y).
     * Returns 1 when an upgrade is pending, -1 when the database is newer than the files, 0 otherwise.
     */
    public static function compareFilesAndDatabase(string $filesVersion, string $databaseVersion, bool $ignorePatchLevel = false): int
    {
        $files = explode('.', self::clean($filesVersion));
        $database = explode('.', self::clean($databaseVersion));
        $levels = $ignorePatchLevel ? 2 : 3;
        for ($i = 0; $i < $levels; $i++) {
            $f = isset($files[$i]) ? (int) $files[$i] : 0;
            $d = isset($database[$i]) ? (int) $database[$i] : 0;
            if ($f > $d) {
                return 1;
            }
            if ($f < $d) {
                return -1;
            }
        }

        return 0;
    }
}
