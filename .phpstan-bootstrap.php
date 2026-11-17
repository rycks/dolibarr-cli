<?php

// Constants defined by Dolibarr's master.inc.php at run time. DOL_DOCUMENT_ROOT
// points to the SQLite test Dolibarr (dev dependency) so that require paths resolve.
define('DOL_DOCUMENT_ROOT', __DIR__.'/vendor/cap-rel/dolibarr-integration-sqlite/htdocs');
define('DOL_DATA_ROOT', __DIR__.'/vendor/cap-rel/dolibarr-integration-sqlite/documents');
define('DOL_VERSION', '18.0.0');
define('MAIN_DB_PREFIX', 'llx_');
