<?php

namespace Dolicli\Setup;

use Dolicli\Cli\Failure;
use Dolicli\Instance\Instance;
use Dolicli\Instance\InstancesIni;
use Dolicli\System\Files;
use Dolicli\System\Log;
use Dolicli\System\Paths;
use Dolicli\System\Posix;
use Dolicli\System\Process;

/**
 * Installed mode: launcher and body owned by root, state directories per run_as, cron file
 * generated from /etc/dolicli/instances.ini.
 */
final class Installer
{
    const INI_TEMPLATE = <<<'INI'
; Instances managed by dolicli (one section per instance). Written by dolifleet setup, or by hand:
;
; [client-a-prod]
; htdocs = /var/www/client-a/htdocs
; run_as = www-data
; php = /usr/bin/php7.4
; web_php = 7.4
; dolibarr_user = dolicli
; maintenance_login = admin
; backup_dir = /var/backups/client-a
; backup_pattern = *.sql.gz
; prom_dir = /var/lib/node_exporter/textfile
; monitor_group = zabbix

INI;

    /** @var array<int, string> */
    private $actions = [];

    /**
     * @return array<int, string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * Version written in a body file, empty when not found.
     */
    public static function bodyVersion(string $body): string
    {
        $content = @file_get_contents($body);

        return is_string($content) && 1 === preg_match("/const VERSION = '([^']*)';/", $content, $m) ? $m[1] : '';
    }

    /**
     * Copy the launcher and the body, refuse to replace a newer installed body without $force.
     */
    public function installCode(string $launcher, string $body, bool $force)
    {
        if ('dev' === self::bodyVersion($body) || '' === self::bodyVersion($body)) {
            throw Failure::usage('BODY_NOT_BUILT', "{$body} is not a built body: run make build, then install dist/dolicli.php.");
        }
        $installed = self::bodyVersion(Paths::installedBody());
        $new = self::bodyVersion($body);
        if ('' !== $installed && !$force && self::newer($installed, $new)) {
            throw Failure::operation('NEWER_INSTALLED', "The installed dolicli {$installed} is newer than {$new}: add --force to downgrade.");
        }
        $lint = new Process();
        if (0 !== $lint->run([PHP_BINARY, '-l', $body])) {
            throw Failure::operation('BODY_INVALID', "php -l refuses {$body}: " . trim($lint->stdout() . ' ' . $lint->stderr()));
        }
        foreach ([[$launcher, Paths::installedLauncher(), 0755], [$body, Paths::installedBody(), 0644]] as $copy) {
            list($from, $to, $mode) = $copy;
            Files::ensureDir(dirname($to), 0755);
            $content = @file_get_contents($from);
            if (!is_string($content)) {
                throw Failure::operation('READ_FAILED', "Cannot read {$from}.");
            }
            Files::writeAtomic($to, $content, $mode);
            if (Posix::isRoot()) {
                @chown($to, 0);
                @chgrp($to, 0);
            }
            $this->actions[] = "Installed {$to} ({$new})";
        }
    }

    /**
     * Directories of the state files, instances.ini template, prom_dir, cron file.
     *
     * @return array<int, string> Warnings
     */
    public function configure(): array
    {
        $warnings = [];
        Files::ensureDir(Paths::etcDir(), 0755);
        if (!is_file(Paths::instancesIni())) {
            Files::writeAtomic(Paths::instancesIni(), self::INI_TEMPLATE, 0644);
            $this->actions[] = 'Wrote the template ' . Paths::instancesIni();
            $warnings[] = 'No instance declared yet: fill ' . Paths::instancesIni() . ' and run dolicli setup install again.';
        }
        $ini = InstancesIni::load();
        foreach ($ini->errors() as $error) {
            $warnings[] = 'instances.ini: ' . $error;
        }

        Files::ensureDir(Paths::stateDir(), 0755);
        $promDirs = [];
        foreach ($ini->all() as $instance) {
            $runAs = $instance->setting('run_as');
            if ('' === $runAs) {
                $warnings[] = "[{$instance->id()}] has no run_as: no collection for it";
                continue;
            }
            if ('' === $instance->setting('php')) {
                $warnings[] = "[{$instance->id()}] has no php: the cron needs the PHP CLI of the instance";
            }
            $this->stateDirectory($runAs, $instance->setting('monitor_group'));
            $promDir = $instance->setting('prom_dir');
            if ('' !== $promDir) {
                $promDirs[$promDir][$runAs] = true;
            }
            if (!self::cronAllowed($runAs)) {
                $warnings[] = "{$runAs} is excluded from cron (/etc/cron.allow or /etc/cron.deny): no collection for [{$instance->id()}]";
            }
        }
        foreach ($promDirs as $dir => $accounts) {
            $warning = $this->promDirectory($dir, array_keys($accounts));
            if ('' !== $warning) {
                $warnings[] = $warning;
            }
        }

        Files::writeAtomic(Paths::cronFile(), self::cron($ini->all()), 0644);
        $this->actions[] = 'Wrote ' . Paths::cronFile();
        foreach ($warnings as $warning) {
            Log::warning('setup: ' . $warning);
        }

        return $warnings;
    }

    /**
     * Cron lines: fast collection every 5 minutes, slow one at night (minute spread by instance),
     * .prom file when prom_dir is declared. The PHP CLI of the instance runs the launcher: its
     * shebang would take the default PHP of the system.
     *
     * @param array<string, Instance> $instances
     */
    public static function cron(array $instances): string
    {
        $out = "# Written by dolicli setup install from /etc/dolicli/instances.ini: do not edit.\nMAILTO=\"\"\n";
        $launcher = Paths::installedLauncher();
        foreach ($instances as $id => $instance) {
            $runAs = $instance->setting('run_as');
            $php = $instance->setting('php');
            if ('' === $runAs || '' === $php) {
                continue;
            }
            $base = $runAs . '  ' . $php . ' ' . $launcher . ' ';
            $minute = abs(crc32($id)) % 60;
            $out .= "*/5 * * * *  {$base}monitor collect --instance={$id} --quiet\n";
            $out .= "{$minute} 3 * * *  {$base}monitor collect --instance={$id} --slow --quiet\n";
            $promDir = $instance->setting('prom_dir');
            if ('' !== $promDir) {
                $out .= "*/5 * * * *  {$base}monitor show --instance={$id} --format=prometheus --output=" . rtrim($promDir, '/') . "/dolibarr-{$id}.prom\n";
            }
        }

        return $out;
    }

    /**
     * Remove what install created: cron file, launcher, body (and with $purge, /etc/dolicli and the state files).
     */
    public function uninstall(bool $purge)
    {
        foreach ([Paths::cronFile(), Paths::installedLauncher(), Paths::installedBody()] as $file) {
            if (is_file($file)) {
                if (!@unlink($file)) {
                    throw Failure::operation('REMOVE_FAILED', "Cannot remove {$file}.");
                }
                $this->actions[] = "Removed {$file}";
            }
        }
        @rmdir(dirname(Paths::installedBody()));
        if ($purge) {
            foreach ([Paths::etcDir(), Paths::stateDir()] as $dir) {
                if (is_dir($dir)) {
                    Files::removeTree($dir);
                    $this->actions[] = "Removed {$dir}";
                }
            }
        }
    }

    /**
     * /var/lib/dolicli/<run_as>/: owner run_as, group of supervision, mode 2750.
     */
    private function stateDirectory(string $runAs, string $group)
    {
        $dir = Paths::stateDir() . '/' . $runAs;
        Files::ensureDir($dir, 02750);
        $uid = Posix::userId($runAs);
        if ($uid < 0) {
            throw Failure::operation('UNKNOWN_ACCOUNT', "Account {$runAs} does not exist.");
        }
        if (Posix::isRoot()) {
            @chown($dir, $uid);
            if ('' !== $group) {
                if (Posix::groupId($group) < 0) {
                    throw Failure::operation('UNKNOWN_GROUP', "Group {$group} does not exist.");
                }
                @chgrp($dir, $group);
            }
        } elseif (Posix::effectiveUid() !== $uid) {
            throw Failure::operation('ROOT_REQUIRED', "{$dir} must belong to {$runAs}: run dolicli setup install as root.");
        }
        @chmod($dir, 02750);
        $this->actions[] = "Prepared {$dir} (owner {$runAs}" . ('' !== $group ? ", group {$group}" : '') . ', mode 2750)';
    }

    /**
     * prom_dir: run_as writes its .prom file there atomically, so it needs the directory.
     *
     * @param array<int, string> $accounts
     */
    private function promDirectory(string $dir, array $accounts): string
    {
        Files::ensureDir($dir, 0755);
        if (1 !== count($accounts)) {
            return "prom_dir {$dir} is shared by " . implode(', ', $accounts) . ': give each run_as its own prom_dir';
        }
        if (Posix::isRoot()) {
            $info = function_exists('posix_getpwnam') ? posix_getpwnam($accounts[0]) : false;
            if (is_array($info)) {
                @chgrp($dir, (int) $info['gid']);
                @chmod($dir, 01775);
            }
        }
        $this->actions[] = "Prepared {$dir} for {$accounts[0]}";

        return '';
    }

    private static function cronAllowed(string $account): bool
    {
        if (is_file('/etc/cron.allow')) {
            $allowed = array_map('trim', (array) file('/etc/cron.allow'));

            return in_array($account, $allowed, true);
        }
        if (is_file('/etc/cron.deny')) {
            $denied = array_map('trim', (array) file('/etc/cron.deny'));

            return !in_array($account, $denied, true);
        }

        return true;
    }

    private static function newer(string $a, string $b): bool
    {
        $pa = 1 === preg_match('/^v?(\d+\.\d+\.\d+)(?:-(\d+))?/', $a, $ma) ? $ma : null;
        $pb = 1 === preg_match('/^v?(\d+\.\d+\.\d+)(?:-(\d+))?/', $b, $mb) ? $mb : null;
        if (null === $pa || null === $pb) {
            return false;
        }
        $compare = version_compare($pa[1], $pb[1]);
        if (0 !== $compare) {
            return $compare > 0;
        }

        return (int) (isset($pa[2]) ? $pa[2] : 0) > (int) (isset($pb[2]) ? $pb[2] : 0);
    }

    /**
     * Built body and launcher running now (dist/ or the installed ones).
     *
     * @return array{0: string, 1: string} Launcher, body
     */
    public static function runningFiles(): array
    {
        $body = '';
        foreach (get_included_files() as $file) {
            if ('' !== self::bodyVersion($file) && 'dev' !== self::bodyVersion($file)) {
                $body = $file;
            }
        }
        $launcher = '' === $body ? '' : dirname($body) . '/dolicli';
        if ('' !== $body && !is_file($launcher)) {
            $launcher = Paths::installedLauncher();
        }

        return [$launcher, $body];
    }
}
