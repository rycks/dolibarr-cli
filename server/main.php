<?php

/**
 * Global code of the body: last block of the built file.
 *
 * Runs at global scope so that master.inc.php creates $conf, $db and $langs as
 * global variables. In the ephemeral mode the script arrives on stdin: STDIN,
 * STDOUT and STDERR are not defined before PHP 8.5, and the arguments come in
 * $DOLICLI_ARGV (program name first) instead of $argv.
 */

namespace {
    if (!defined('STDIN')) {
        define('STDIN', fopen('php://stdin', 'r'));
    }
    if (!defined('STDOUT')) {
        define('STDOUT', fopen('php://stdout', 'w'));
    }
    if (!defined('STDERR')) {
        define('STDERR', fopen('php://stderr', 'w'));
    }

    if (PHP_SAPI !== 'cli') {
        exit(\Dolicli\Cli\ExitCode::ENVIRONMENT);
    }

    $dolicliFromStream = isset($DOLICLI_ARGV) && is_array($DOLICLI_ARGV);
    $dolicliApp = new \Dolicli\Cli\Application(
        $dolicliFromStream ? $DOLICLI_ARGV : $argv,
        $dolicliFromStream
    );
    $dolicliMaster = $dolicliApp->prepare();
    if ('' !== $dolicliMaster) {
        require_once $dolicliMaster;
        $dolicliApp->dolibarrLoaded();
    }
    exit($dolicliApp->run());
}
