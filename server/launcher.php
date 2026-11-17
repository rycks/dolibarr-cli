#!/usr/bin/env php
<?php
/*
 * dolicli launcher - Dolibarr administration from the command line.
 *
 * Copyright (c) 2025-2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * This file must stay readable by any PHP 5: it checks the version of PHP and the
 * json extension, then includes the body. Without it, a PHP too old would fail on a
 * parse error of the body, since PHP parses a whole file before running it.
 * No [] arrays, no __DIR__, no PHP_VERSION_ID (absent before 5.2.7).
 */

if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    fwrite(STDERR, 'dolicli needs PHP 7.0 or later, this is PHP ' . PHP_VERSION . ' (' . (defined('PHP_BINARY') ? constant('PHP_BINARY') : 'php') . "): run it with a newer PHP CLI, for example /usr/bin/php7.4 " . $_SERVER['argv'][0] . "\n");
    exit(3);
}
if (!function_exists('json_encode')) {
    fwrite(STDERR, 'dolicli needs the json extension of PHP ' . PHP_VERSION . " (package php" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "-json on Debian and Ubuntu)\n");
    exit(3);
}

$dolicliLauncherDir = dirname(realpath(__FILE__));
$dolicliBody = '';
$dolicliCandidates = array(
    getenv('DOLICLI_BODY'),
    $dolicliLauncherDir . '/dolicli.php',
    dirname($dolicliLauncherDir) . '/lib/dolicli/dolicli.php',
    $dolicliLauncherDir . '/dev.php',
    $dolicliLauncherDir . '/server/dev.php',
);
foreach ($dolicliCandidates as $dolicliCandidate) {
    if (is_string($dolicliCandidate) && '' !== $dolicliCandidate && is_file($dolicliCandidate)) {
        $dolicliBody = $dolicliCandidate;
        break;
    }
}
if ('' === $dolicliBody) {
    fwrite(STDERR, "dolicli body not found next to the launcher, in ../lib/dolicli/ or in DOLICLI_BODY\n");
    exit(3);
}
unset($dolicliCandidates, $dolicliCandidate, $dolicliLauncherDir);

require $dolicliBody;
