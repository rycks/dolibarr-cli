<?php

namespace Dolicli\Php;

/**
 * Highest PHP version known to work, per first Dolibarr version of a range, with its source.
 *
 * Up to 15.0, check.php declares no maximum although these versions do not work with
 * recent PHP: taken as is, the declared matrix would let Dolibarr 12 run under PHP 8.4.
 * Kept by hand; a Dolibarr version below the first entry has no established maximum
 * (compatibility unknown). From 16.0, the declared maximum applies.
 */
final class ObservedMatrix
{
    const MAXIMUMS = [
        '7.0.0' => ['max' => '7.2', 'source' => 'Dolibarr ChangeLog 7.0.0: "NEW: Compatibility with PHP 7.2"'],
        '9.0.0' => ['max' => '7.3', 'source' => 'Dolibarr ChangeLog 9.0.0: "NEW: Compatibility with PHP 7.3"'],
        '11.0.0' => ['max' => '7.4', 'source' => 'Dolibarr ChangeLog 11.0.0: "NEW: Compatible with PHP 7.4"'],
        '13.0.0' => ['max' => '7.4', 'source' => 'Dolibarr ChangeLog 13.0.0: "still compatible with any PHP version between 5.6.0 and 7.4.*"'],
    ];
}
