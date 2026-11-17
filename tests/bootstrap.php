<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// Private temporary directory for the whole run, removed at shutdown.
$user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'user') : 'user';
$tmpRoot = '/tmp/'.$user.'/dolicli/'.getmypid();
if ( ! is_dir($tmpRoot) && ! mkdir($tmpRoot, 0700, true) && ! is_dir($tmpRoot)) {
    fwrite(STDERR, "Cannot create test temporary directory {$tmpRoot}\n");
    exit(1);
}
putenv('TMPDIR='.$tmpRoot);
$_ENV['TMPDIR'] = $tmpRoot;
define('DOLICLI_TEST_TMP', $tmpRoot);

register_shutdown_function(static function () use ($tmpRoot): void {
    if (is_dir($tmpRoot)) {
        exec('rm -rf '.escapeshellarg($tmpRoot));
    }
});

define('DOLICLI_ROOT', dirname(__DIR__));
define('DOLICLI_DOLPATH', DOLICLI_ROOT.'/vendor/cap-rel/dolibarr-integration-sqlite/htdocs');
define('DOLICLI_TEST_DB', DOLICLI_ROOT.'/vendor/cap-rel/dolibarr-integration-sqlite/documents/database_dolibarr.sdb');
define('DOLICLI_TEST_DB_BACKUP', $tmpRoot.'/database_dolibarr.sdb.orig');

if ( ! is_file(DOLICLI_TEST_DB)) {
    fwrite(STDERR, 'SQLite Dolibarr database not found: '.DOLICLI_TEST_DB." (run composer install)\n");
    exit(1);
}
// Top-level files of documents/ before the run: files a test adds (install.lock...) are removed after it.
define('DOLICLI_TEST_DOCUMENTS', dirname(DOLICLI_TEST_DB));
$GLOBALS['dolicliInitialDocuments'] = array_values(array_filter(
    (array) scandir(DOLICLI_TEST_DOCUMENTS),
    static fn ($f): bool => is_string($f) && is_file(DOLICLI_TEST_DOCUMENTS.'/'.$f)
));

// The server tests run the built body: build it once for the run, in the private directory.
define('DOLICLI_TEST_DIST', $tmpRoot.'/dist');
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(DOLICLI_ROOT.'/build/build-server.php').' --output='.escapeshellarg(DOLICLI_TEST_DIST).' --version=test 2>&1', $buildOutput, $buildCode);
if (0 !== $buildCode) {
    fwrite(STDERR, "Cannot build the dolicli body:\n".implode("\n", $buildOutput)."\n");
    exit(1);
}

if ( ! copy(DOLICLI_TEST_DB, DOLICLI_TEST_DB_BACKUP)) {
    fwrite(STDERR, "Cannot back up the SQLite Dolibarr database\n");
    exit(1);
}
register_shutdown_function(static function (): void {
    if (is_file(DOLICLI_TEST_DB_BACKUP)) {
        copy(DOLICLI_TEST_DB_BACKUP, DOLICLI_TEST_DB);
    }
});
