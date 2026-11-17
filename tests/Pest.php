<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/ServerHelpers.php';

// Every test starts from the pristine SQLite Dolibarr database and empty system directories.
pest()->afterEach(function (): void {
    restoreTestDb();
    resetServerEnvDirs();
})->in('Server');
