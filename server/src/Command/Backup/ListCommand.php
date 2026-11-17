<?php

namespace Dolicli\Command\Backup;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Text;
use Dolicli\Instance\BackupFiles;
use Dolicli\System\Log;
use Dolicli\System\Posix;

/**
 * Backup files of the instance, newest first. Reads documents/ through conf.php without loading
 * Dolibarr: it answers even when the database is unreachable. Never returns file contents.
 */
final class ListCommand extends Command
{
    public function name(): string
    {
        return 'backup list';
    }

    public function summary(): string
    {
        return 'List the backup files of the instance, newest first';
    }

    public function needsDolibarr(): bool
    {
        return false;
    }

    public function needsInstance(): bool
    {
        return true;
    }

    public function help(): string
    {
        return "Searches documents/admin/backup (database dumps of the Dolibarr admin tools) and the backup_dir of\n"
            . "instances.ini, with its backup_pattern (default *): the places of the monitor check backup.age.\n"
            . "kind comes from the name: database (*.sql, .sql.gz, .bz2, .zst, .xz), documents (-documents.zip,\n"
            . "documents_*.zip or .tar.*), modules (-modules.zip), unknown otherwise. Directories that exist but\n"
            . 'cannot be listed are reported in unreadable, with a warning.';
    }

    public function execute(Context $ctx): array
    {
        $finder = new BackupFiles($ctx->instance(), $ctx->dataRoot());
        $now = time();
        $seen = [];
        $backups = [];
        foreach ($finder->matches() as $path) {
            if (!is_file($path)) {
                continue;
            }
            $real = realpath($path);
            $key = false === $real ? $path : $real;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $mtime = @filemtime($path);
            $bytes = @filesize($path);
            if (false === $mtime || false === $bytes) {
                Log::warning("backup list: cannot read the size or date of {$path}");
                continue;
            }
            $backups[] = [
                'file' => basename($path),
                'path' => $path,
                'kind' => BackupFiles::kind($path),
                'bytes' => (int) $bytes,
                'date' => date('c', $mtime),
                'age_seconds' => max(0, $now - $mtime),
            ];
        }
        usort($backups, [self::class, 'compare']);

        $unreadable = $finder->unreadable();
        foreach ($unreadable as $dir) {
            $ctx->warn('BACKUP_DIR_UNREADABLE', "Backup directory {$dir} exists but cannot be listed by " . Posix::currentUserName() . '.');
        }

        return [
            'backups' => $backups,
            'count' => count($backups),
            'latest' => [] === $backups ? null : $backups[0]['date'],
            'dirs' => $finder->dirs(),
            'unreadable' => $unreadable,
        ];
    }

    public function renderText(array $data): string
    {
        if ([] === $data['backups']) {
            return 'No backup found in ' . implode(', ', $data['dirs']) . ".\n";
        }
        $rows = [];
        foreach ($data['backups'] as $backup) {
            $rows[] = [$backup['file'], $backup['kind'], self::size($backup['bytes']), str_replace('T', ' ', substr($backup['date'], 0, 16)), self::age($backup['age_seconds'])];
        }

        return Text::table(['File', 'Kind', 'Size', 'Date', 'Age'], $rows) . "\nSearched: " . implode(', ', $data['dirs']) . "\n";
    }

    /**
     * Newest first, then by path.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function compare(array $a, array $b): int
    {
        if ($a['age_seconds'] !== $b['age_seconds']) {
            return $a['age_seconds'] < $b['age_seconds'] ? -1 : 1;
        }

        return strcmp($a['path'], $b['path']);
    }

    private static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }

    private static function age(int $seconds): string
    {
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 86400) {
            return sprintf('%.1f h', $seconds / 3600);
        }

        return sprintf('%.1f d', $seconds / 86400);
    }
}
