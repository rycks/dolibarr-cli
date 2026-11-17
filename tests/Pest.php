<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

// Every integration test starts from the pristine SQLite Dolibarr database.
pest()->afterEach(fn () => restoreTestDb())->in('Integration');
