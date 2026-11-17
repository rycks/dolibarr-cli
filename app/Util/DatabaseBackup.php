<?php

declare(strict_types=1);

namespace App\Util;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Database dump and directory archives, without going through a shell:
 * external tools are started with an argument array, secrets never appear
 * on a command line, produced files are created with mode 0600.
 */
final class DatabaseBackup
{
    private string $lastError = '';

    public function getLastError(): string
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
        $password = (string) ($dolibarr_main_db_pass ?? '');

        if ( ! $this->createPrivateFile($target)) {
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
    public function zipDirectory(string $sourceDir, string $target, ?string $excludePattern = null): bool
    {
        $source = realpath($sourceDir);
        if (false === $source || ! is_dir($source)) {
            return $this->failWith("Directory not found: {$sourceDir}");
        }
        if ( ! class_exists(ZipArchive::class)) {
            return $this->failWith('PHP zip extension is missing.');
        }
        if ( ! $this->createPrivateFile($target)) {
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

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $relative = mb_substr($path, mb_strlen($source) + 1);
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);

                continue;
            }
            if ($path === $targetReal || (null !== $excludePattern && 1 === preg_match($excludePattern, $file->getFilename()))) {
                continue;
            }
            if ( ! $file->isReadable()) {
                DolibarrHelper::log("backup: unreadable file skipped: {$path}", LOG_WARNING);

                continue;
            }
            $zip->addFile($path, $relative);
        }

        if ( ! $zip->close()) {
            return $this->failWith("Cannot write archive {$target}: ".$zip->getStatusString(), $target);
        }
        chmod($target, 0600);

        return true;
    }

    private function dumpMysql(string $target, string $password): bool
    {
        global $conf;

        $binary = $this->findBinary(['mysqldump', 'mariadb-dump']);
        if (null === $binary) {
            return $this->failWith('mysqldump (or mariadb-dump) not found in PATH.', $target);
        }

        // Credentials go in a private option file, never on the command line (visible in ps).
        $optionFile = tempnam(sys_get_temp_dir(), 'dolicli-my');
        if (false === $optionFile) {
            return $this->failWith('Cannot create a temporary option file for mysqldump.', $target);
        }
        chmod($optionFile, 0600);
        $options = "[client]\n"
            .'user="'.addcslashes((string) $conf->db->user, '"\\').'"'."\n"
            .'password="'.addcslashes($password, '"\\').'"'."\n"
            .'host="'.addcslashes((string) $conf->db->host, '"\\').'"'."\n";
        if ( ! empty($conf->db->port)) {
            $options .= 'port='.(int) $conf->db->port."\n";
        }
        file_put_contents($optionFile, $options);

        try {
            $ok = $this->run(
                [$binary, '--defaults-extra-file='.$optionFile, '--single-transaction', '--quick', '--routines', (string) $conf->db->name],
                $target,
                []
            );
        } finally {
            unlink($optionFile);
        }

        return $ok ? $this->checkNotEmpty($target) : $this->failWith($this->lastError, $target);
    }

    private function dumpPgsql(string $target, string $password): bool
    {
        global $conf;

        $binary = $this->findBinary(['pg_dump']);
        if (null === $binary) {
            return $this->failWith('pg_dump not found in PATH.', $target);
        }

        $args = [$binary, '--no-password', '-h', (string) $conf->db->host, '-U', (string) $conf->db->user];
        if ( ! empty($conf->db->port)) {
            $args[] = '-p';
            $args[] = (string) (int) $conf->db->port;
        }
        $args[] = (string) $conf->db->name;

        $ok = $this->run($args, $target, ['PGPASSWORD' => $password]);

        return $ok ? $this->checkNotEmpty($target) : $this->failWith($this->lastError, $target);
    }

    private function copySqlite(string $target): bool
    {
        global $conf, $main_data_dir;

        $dir = empty($main_data_dir) ? DOL_DATA_ROOT : $main_data_dir;
        $source = $dir.'/database_'.$conf->db->name.'.sdb';
        if ( ! is_file($source)) {
            return $this->failWith("SQLite database file not found: {$source}", $target);
        }
        if ( ! copy($source, $target)) {
            return $this->failWith("Cannot copy {$source} to {$target}", $target);
        }
        chmod($target, 0600);

        return $this->checkNotEmpty($target);
    }

    /**
     * Run a command (argument array, no shell), its standard output written to $outputFile.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string>  $extraEnv
     */
    private function run(array $command, string $outputFile, array $extraEnv): bool
    {
        $env = getenv();
        foreach ($extraEnv as $name => $value) {
            $env[$name] = $value;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $outputFile, 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if ( ! is_resource($process)) {
            $this->lastError = 'Cannot start '.$command[0];

            return false;
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if (0 !== $code) {
            $this->lastError = basename($command[0])." failed (exit code {$code}): ".trim($stderr);

            return false;
        }

        return true;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function findBinary(array $names): ?string
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($names as $name) {
            foreach ($paths as $dir) {
                if ('' !== $dir && is_file($dir.'/'.$name) && is_executable($dir.'/'.$name)) {
                    return $dir.'/'.$name;
                }
            }
        }

        return null;
    }

    private function createPrivateFile(string $target): bool
    {
        $dir = dirname($target);
        if ( ! is_dir($dir) || ! is_writable($dir)) {
            return $this->failWith("Destination directory is not writable: {$dir}");
        }
        if (false === @file_put_contents($target, '') || ! chmod($target, 0600)) {
            return $this->failWith("Cannot create {$target}");
        }

        return true;
    }

    private function checkNotEmpty(string $target): bool
    {
        clearstatcache(true, $target);
        if ( ! is_file($target) || 0 === filesize($target)) {
            return $this->failWith("Backup file is empty: {$target}", $target);
        }

        return true;
    }

    private function failWith(string $message, ?string $partialFile = null): bool
    {
        $this->lastError = $message;
        DolibarrHelper::log('backup: '.$message, LOG_ERR);
        if (null !== $partialFile && is_file($partialFile)) {
            unlink($partialFile);
        }

        return false;
    }
}
