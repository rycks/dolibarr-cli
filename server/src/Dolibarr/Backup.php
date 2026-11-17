<?php

namespace Dolicli\Dolibarr;

use Dolicli\System\Log;
use Dolicli\System\Process;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Database dump and directory archives. External tools get an argument list (escaped
 * before PHP 7.4), secrets never appear on a command line, files are created with mode 0600.
 */
final class Backup
{
    /** @var string */
    private $lastError = '';

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * Dump the Dolibarr database into $target (SQL text for MySQL/PostgreSQL, file copy for SQLite).
     */
    public function dumpDatabase(string $target): bool
    {
        global $conf, $dolibarr_main_db_pass;

        $type = (string) $conf->db->type;
        $password = isset($dolibarr_main_db_pass) ? (string) $dolibarr_main_db_pass : '';
        if (!$this->createPrivateFile($target)) {
            return false;
        }
        switch ($type) {
            case 'mysql':
            case 'mysqli':
                return $this->dumpMysql($target, $password);
            case 'pgsql':
                return $this->dumpPgsql($target, $password);
            case 'sqlite3':
                return $this->copySqlite($target);
            default:
                return $this->failWith("Database type '{$type}' is not supported for backup.", $target);
        }
    }

    /**
     * Zip a directory recursively. Files whose name matches $excludePattern are skipped,
     * as is the archive itself when it is written inside the directory.
     */
    public function zipDirectory(string $sourceDir, string $target, string $excludePattern = ''): bool
    {
        $source = realpath($sourceDir);
        if (false === $source || !is_dir($source)) {
            return $this->failWith("Directory not found: {$sourceDir}");
        }
        if (!class_exists('ZipArchive')) {
            return $this->failWith('PHP zip extension is missing.');
        }
        if (!$this->createPrivateFile($target)) {
            return false;
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            return $this->failWith("Cannot create archive {$target}", $target);
        }
        $targetReal = realpath($target);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen($source) + 1);
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            if ($path === $targetReal || ('' !== $excludePattern && 1 === preg_match($excludePattern, $file->getFilename()))) {
                continue;
            }
            if (!$file->isReadable()) {
                Log::warning("backup: unreadable file skipped: {$path}");
                continue;
            }
            $zip->addFile($path, $relative);
        }
        if (!$zip->close()) {
            return $this->failWith("Cannot write archive {$target}: " . $zip->getStatusString(), $target);
        }
        chmod($target, 0600);

        return true;
    }

    private function dumpMysql(string $target, string $password): bool
    {
        global $conf;

        $binary = Process::find(['mysqldump', 'mariadb-dump']);
        if ('' === $binary) {
            return $this->failWith('mysqldump (or mariadb-dump) not found in PATH.', $target);
        }
        // Credentials go in a private option file, never on the command line (visible in ps).
        $optionFile = @tempnam(dirname($target), '.dolicli-my');
        if (false === $optionFile) {
            return $this->failWith('Cannot create a temporary option file for mysqldump.', $target);
        }
        chmod($optionFile, 0600);
        $options = "[client]\n"
            . 'user="' . addcslashes((string) $conf->db->user, '"\\') . '"' . "\n"
            . 'password="' . addcslashes($password, '"\\') . '"' . "\n"
            . 'host="' . addcslashes((string) $conf->db->host, '"\\') . '"' . "\n";
        if (!empty($conf->db->port)) {
            $options .= 'port=' . (int) $conf->db->port . "\n";
        }
        file_put_contents($optionFile, $options);

        $process = new Process();
        try {
            $code = $process->run(
                [$binary, '--defaults-extra-file=' . $optionFile, '--single-transaction', '--quick', '--routines', (string) $conf->db->name],
                [],
                $target
            );
        } finally {
            @unlink($optionFile);
        }
        if (0 !== $code) {
            return $this->failWith(basename($binary) . " failed (exit code {$code}): " . $process->error(), $target);
        }

        return $this->checkNotEmpty($target);
    }

    private function dumpPgsql(string $target, string $password): bool
    {
        global $conf;

        $binary = Process::find(['pg_dump']);
        if ('' === $binary) {
            return $this->failWith('pg_dump not found in PATH.', $target);
        }
        $args = [$binary, '--no-password', '-h', (string) $conf->db->host, '-U', (string) $conf->db->user];
        if (!empty($conf->db->port)) {
            $args[] = '-p';
            $args[] = (string) (int) $conf->db->port;
        }
        $args[] = (string) $conf->db->name;
        $process = new Process();
        $code = $process->run($args, ['PGPASSWORD' => $password], $target);
        if (0 !== $code) {
            return $this->failWith("pg_dump failed (exit code {$code}): " . $process->error(), $target);
        }

        return $this->checkNotEmpty($target);
    }

    private function copySqlite(string $target): bool
    {
        global $conf, $main_data_dir;

        $dir = empty($main_data_dir) ? DOL_DATA_ROOT : $main_data_dir;
        $source = $dir . '/database_' . $conf->db->name . '.sdb';
        if (!is_file($source)) {
            return $this->failWith("SQLite database file not found: {$source}", $target);
        }
        if (!copy($source, $target)) {
            return $this->failWith("Cannot copy {$source} to {$target}", $target);
        }
        chmod($target, 0600);

        return $this->checkNotEmpty($target);
    }

    private function createPrivateFile(string $target): bool
    {
        $dir = dirname($target);
        if (!is_dir($dir) || !is_writable($dir)) {
            return $this->failWith("Destination directory is not writable: {$dir}");
        }
        $old = umask(0077);
        $created = @file_put_contents($target, '');
        umask($old);
        if (false === $created || !chmod($target, 0600)) {
            return $this->failWith("Cannot create {$target}");
        }

        return true;
    }

    private function checkNotEmpty(string $target): bool
    {
        clearstatcache(true, $target);
        if (!is_file($target) || 0 === filesize($target)) {
            return $this->failWith("Backup file is empty: {$target}", $target);
        }

        return true;
    }

    /**
     * @param string $partialFile File to remove (incomplete backup), empty for none
     */
    private function failWith(string $message, string $partialFile = ''): bool
    {
        $this->lastError = $message;
        Log::error('backup: ' . $message);
        if ('' !== $partialFile && is_file($partialFile)) {
            @unlink($partialFile);
        }

        return false;
    }
}
