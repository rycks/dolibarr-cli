<?php

namespace Dolicli\Php;

use Dolicli\Upgrade\MigrationPlanner;

/**
 * Compatibility of a PHP version with a Dolibarr version (appendix A of the specification):
 * declared values of check.php, with the real maximums of ObservedMatrix for the versions
 * that declare none.
 */
final class Matrix
{
    const OK = 'ok';
    const WARNING = 'warning';
    const INCOMPATIBLE = 'incompatible';
    const UNKNOWN = 'unknown';

    /**
     * @return array{min: string, recommended: string, max: string, max_source: string}
     */
    public static function range(string $dolibarrVersion): array
    {
        $version = MigrationPlanner::clean($dolibarrVersion);
        $declared = ['min' => '', 'recommended' => '', 'max' => ''];
        foreach (DeclaredMatrix::RANGES as $since => $values) {
            if (version_compare($version, $since, '>=')) {
                $declared = $values;
            }
        }
        $max = $declared['max'];
        $source = '' !== $max ? 'install/check.php of Dolibarr ' . $version : '';
        if ('' === $max) {
            foreach (ObservedMatrix::MAXIMUMS as $since => $observed) {
                if (version_compare($version, $since, '>=')) {
                    $max = $observed['max'];
                    $source = $observed['source'];
                }
            }
        }

        return ['min' => $declared['min'], 'recommended' => $declared['recommended'], 'max' => $max, 'max_source' => $source];
    }

    /**
     * ok, warning (below the advised minimum), incompatible, or unknown (no established maximum).
     */
    public static function check(string $dolibarrVersion, string $phpVersion): string
    {
        $range = self::range($dolibarrVersion);
        $php = self::minor($phpVersion);
        if ('' === $range['min'] || '' === $php) {
            return self::UNKNOWN;
        }
        if (version_compare($php, $range['min'], '<')) {
            return self::INCOMPATIBLE;
        }
        if ('' === $range['max']) {
            return self::UNKNOWN;
        }
        if (version_compare($php, $range['max'], '>')) {
            return self::INCOMPATIBLE;
        }

        return version_compare($php, $range['recommended'], '<') ? self::WARNING : self::OK;
    }

    /**
     * x.y of a PHP version (7.4.33 -> 7.4).
     */
    public static function minor(string $phpVersion): string
    {
        return 1 === preg_match('/^(\d+)\.(\d+)/', $phpVersion, $m) ? $m[1] . '.' . $m[2] : '';
    }
}
