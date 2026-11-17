<?php

namespace Dolicli\Command;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Backup;

final class BackupCommand extends Command
{
    public function name(): string
    {
        return 'backup';
    }

    public function summary(): string
    {
        return 'Back up the database, documents and external modules';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::flag('database', 'Dump the database (mysqldump, pg_dump, or a copy of the SQLite file)'),
            Param::flag('documents', 'Zip the documents directory (*.log files excluded)'),
            Param::flag('modules', 'Zip the external modules directory (htdocs/custom)'),
            Param::value('dir', 'PATH', 'Destination directory (default: system temporary directory)'),
        ];
    }

    public function help(): string
    {
        return "Files are named YYYYMMDD-HHMMSS-database.sql, -documents.zip, -modules.zip and created with\n"
            . 'mode 0600 (readable by their owner only). A failed backup removes its incomplete file.';
    }

    public function execute(Context $ctx): array
    {
        global $dolibarr_main_data_root, $dolibarr_main_document_root_alt;

        $parts = [];
        foreach (['database', 'documents', 'modules'] as $part) {
            if ($ctx->flag($part)) {
                $parts[] = $part;
            }
        }
        if ([] === $parts) {
            throw Failure::usage('MISSING_PARAMETER', 'Choose at least one of --database, --documents, --modules.');
        }
        $dir = rtrim((string) ($ctx->value('dir') ?: sys_get_temp_dir()), '/');
        if (!is_dir($dir) || !is_writable($dir)) {
            throw Failure::operation('BACKUP_DIR_UNAVAILABLE', "Destination directory does not exist or is not writable: {$dir}");
        }
        $prefix = $dir . '/' . date('Ymd-His');
        // document_root_alt may hold several directories separated by commas: the first one is custom/
        $customDir = empty($dolibarr_main_document_root_alt)
            ? DOL_DOCUMENT_ROOT . '/custom'
            : trim(explode(',', (string) $dolibarr_main_document_root_alt)[0]);

        if ($ctx->dryRun()) {
            $actions = [];
            foreach ($parts as $part) {
                $actions[] = 'Write ' . $prefix . '-' . $part . ('database' === $part ? '.sql' : '.zip');
            }

            return ['dry_run' => true, 'actions' => $actions];
        }

        $backup = new Backup();
        $files = [];
        foreach ($parts as $part) {
            $ctx->progress('Exporting ' . $part . '...');
            if ('database' === $part) {
                $file = $prefix . '-database.sql';
                $ok = $backup->dumpDatabase($file);
            } elseif ('documents' === $part) {
                $file = $prefix . '-documents.zip';
                $ok = $backup->zipDirectory((string) $dolibarr_main_data_root, $file, '/\.log$/');
            } else {
                $file = $prefix . '-modules.zip';
                $ok = $backup->zipDirectory($customDir, $file);
            }
            if (!$ok) {
                $ctx->addError('BACKUP_FAILED', ucfirst($part) . ' export failed: ' . $backup->lastError());
                continue;
            }
            clearstatcache(true, $file);
            $files[] = ['part' => $part, 'file' => $file, 'bytes' => (int) filesize($file)];
        }

        return ['files' => $files];
    }

    public function renderText(array $data): string
    {
        $out = '';
        foreach ($data['files'] as $file) {
            $out .= ucfirst($file['part']) . ' exported in ' . $file['file'] . ' (' . $file['bytes'] . " bytes)\n";
        }

        return $out;
    }
}
