<?php

declare(strict_types=1);

namespace App\Command\Backup;

use App\Support\Command;
use App\Util\DatabaseBackup;

class DefaultController extends Command
{
    public function summary(): string
    {
        return 'Back up the database, documents and external modules';
    }

    public function usage(): void
    {
        $this->info('Dolibarr backups

Usage:
  ./dolibarr backup --database [dir=PATH]
  ./dolibarr backup --documents [dir=PATH]
  ./dolibarr backup --modules [dir=PATH]

Options:
  --database   Dump the database (mysqldump, pg_dump, or a copy of the SQLite file)
  --documents  Zip the documents directory (*.log files excluded)
  --modules    Zip the external modules directory (htdocs/custom)
  dir=PATH     Destination directory (default: system temporary directory)
  --help       Display this help message

Files are named YYYYMMDD-HHMMSS-database.sql, -documents.zip, -modules.zip
and created with mode 0600 (readable by their owner only).');
    }

    public function handle(): void
    {
        global $dolibarr_main_data_root, $dolibarr_main_document_root_alt;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        $doDatabase = $this->hasFlag('database');
        $doDocuments = $this->hasFlag('documents');
        $doModules = $this->hasFlag('modules');
        if ( ! $doDatabase && ! $doDocuments && ! $doModules) {
            $this->usage();
            $this->fail('Choose at least one of --database, --documents, --modules.');

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        $dir = rtrim($this->param('dir') ?? sys_get_temp_dir(), '/');
        if ( ! is_dir($dir) || ! is_writable($dir)) {
            $this->fail("Destination directory does not exist or is not writable: {$dir}");

            return;
        }

        $prefix = $dir.'/'.date('Ymd-His');
        $backup = new DatabaseBackup();

        if ($doDatabase) {
            $file = $prefix.'-database.sql';
            $this->info('Exporting database...');
            if ($backup->dumpDatabase($file)) {
                $this->success('Database exported in '.$file.' ('.$this->size($file).')');
            } else {
                $this->fail('Database export failed: '.$backup->getLastError());
            }
        }

        if ($doDocuments) {
            $file = $prefix.'-documents.zip';
            $this->info('Exporting documents...');
            if ($backup->zipDirectory((string) $dolibarr_main_data_root, $file, '/\.log$/')) {
                $this->success('Documents exported in '.$file.' ('.$this->size($file).')');
            } else {
                $this->fail('Documents export failed: '.$backup->getLastError());
            }
        }

        if ($doModules) {
            // document_root_alt may hold several directories separated by commas: the first one is custom/
            $customDir = empty($dolibarr_main_document_root_alt)
                ? DOL_DOCUMENT_ROOT.'/custom'
                : trim(explode(',', (string) $dolibarr_main_document_root_alt)[0]);
            $file = $prefix.'-modules.zip';
            $this->info('Exporting external modules ('.$customDir.')...');
            if ($backup->zipDirectory($customDir, $file)) {
                $this->success('Modules exported in '.$file.' ('.$this->size($file).')');
            } else {
                $this->fail('Modules export failed: '.$backup->getLastError());
            }
        }
    }

    private function size(string $file): string
    {
        clearstatcache(true, $file);
        $bytes = (int) filesize($file);
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1).' '.$units[$i];
    }
}
