<?php

namespace Dolicli\Instance;

/**
 * Backup files of an instance, read without Dolibarr: documents/admin/backup (database dumps of
 * Dolibarr) and the backup_dir of instances.ini, filtered by backup_pattern (default *). Shared by
 * the monitor check backup.age and by backup list so that they search the same places.
 */
final class BackupFiles
{
    /** @var array<int, string> */
    private $dirs;

    /** @var string */
    private $pattern;

    public function __construct(Instance $instance, string $dataRoot)
    {
        $this->dirs = [$dataRoot . '/admin/backup'];
        if ('' !== $instance->setting('backup_dir')) {
            $this->dirs[] = $instance->setting('backup_dir');
        }
        $this->pattern = $instance->setting('backup_pattern', '*');
    }

    /**
     * Directories searched, in order.
     *
     * @return array<int, string>
     */
    public function dirs(): array
    {
        return $this->dirs;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    /**
     * Paths matching the pattern in every directory, as glob() gives them (directories included).
     *
     * @return array<int, string>
     */
    public function matches(): array
    {
        $matches = [];
        foreach ($this->dirs as $dir) {
            $files = glob(rtrim($dir, '/') . '/' . $this->pattern);
            foreach (is_array($files) ? $files : [] as $file) {
                $matches[] = $file;
            }
        }

        return $matches;
    }

    /**
     * Most recent match and its modification time; ['', 0] when there is none.
     *
     * @return array{0: string, 1: int}
     */
    public function latest(): array
    {
        $latest = 0;
        $latestFile = '';
        foreach ($this->matches() as $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime > $latest) {
                $latest = $mtime;
                $latestFile = $file;
            }
        }

        return [$latestFile, $latest];
    }

    /**
     * Directories that exist but cannot be listed by the current account.
     *
     * @return array<int, string>
     */
    public function unreadable(): array
    {
        $unreadable = [];
        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $handle = @opendir($dir);
            if (false === $handle) {
                $unreadable[] = $dir;
                continue;
            }
            closedir($handle);
        }

        return $unreadable;
    }

    /**
     * Kind of a backup from its name: dolicli backup (YYYYMMDD-HHMMSS-database.sql, -documents.zip,
     * -modules.zip), Dolibarr admin tools (mysqldump_*.sql, pg_dump_*.sql with .gz, .bz2 or .zst,
     * documents_*.zip or .tar with compression), any other SQL dump.
     */
    public static function kind(string $file): string
    {
        $name = strtolower(basename($file));
        if (1 === preg_match('/-modules\.zip$/', $name)) {
            return 'modules';
        }
        if (1 === preg_match('/(-documents\.zip|^documents_.*\.(zip|tar|tgz|tar\.gz|tar\.bz2|tar\.zst))$/', $name)) {
            return 'documents';
        }
        if (1 === preg_match('/\.sql(\.gz|\.bz2|\.zst|\.xz)?$/', $name)) {
            return 'database';
        }

        return 'unknown';
    }
}
