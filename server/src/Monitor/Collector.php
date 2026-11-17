<?php

namespace Dolicli\Monitor;

use Dolicli\Dolibarr\CronJobs;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Dolibarr\Maintenance;
use Dolicli\Dolibarr\Modules;
use Dolicli\Instance\Instance;
use Dolicli\Instance\PhpFileReader;
use Dolicli\Php\Matrix;
use Dolicli\System\Lock;
use Dolicli\System\Log;
use Dolicli\Upgrade\Filecheck;
use Dolicli\Upgrade\MigrationPlanner;
use Dolicli\Upgrade\Workspace;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Measures of the checks (section 5.3 of the specification). Every check that cannot be
 * measured returns unknown with its cause, never an invented value; a check that does not
 * apply returns skipped.
 */
final class Collector
{
    /** Checks that need the database. */
    const DATABASE_CHECKS = [
        'dolibarr.db_version', 'dolibarr.upgrade_pending', 'maintenance.enabled', 'sessions.count', 'users.active',
        'cron.failed', 'cron.stale', 'cron.stuck', 'storage.database_bytes', 'modules.external', 'security.login_failures_24h',
    ];

    const FAST = 'fast';
    const SLOW = 'slow';

    /** Margin before a job whose next run date is past counts as late (seconds). */
    const CRON_STALE_MARGIN = CronJobs::STALE_MARGIN;

    /** A job marked as running for longer than this is stuck (seconds). */
    const CRON_STUCK_AFTER = CronJobs::STUCK_AFTER;

    /** @var Instance */
    private $instance;

    /** @var Thresholds */
    private $thresholds;

    /** @var string */
    private $dataRoot;

    public function __construct(Instance $instance, Thresholds $thresholds, string $dataRoot)
    {
        $this->instance = $instance;
        $this->thresholds = $thresholds;
        $this->dataRoot = $dataRoot;
    }

    /**
     * Checks of one frequency.
     *
     * @param bool $database Whether Dolibarr and its database are loaded
     * @param bool $operation Whether an operation holds the instance: the database is not queried
     * @return array<string, array<string, mixed>>
     */
    public function collect(string $frequency, bool $database, bool $operation, string $loadError = ''): array
    {
        $checks = [];
        $names = self::FAST === $frequency ? self::fastChecks() : self::slowChecks();
        foreach ($names as $name) {
            if (in_array($name, self::DATABASE_CHECKS, true) && (!$database || $operation)) {
                $reason = $operation ? 'Not measured: an operation is running on the instance' : 'Database unreachable';
                $checks[$name] = State::check('unknown', null, $reason, $frequency);
                continue;
            }
            try {
                $checks[$name] = $this->measure($name, $frequency, $database, $loadError);
            } catch (\Throwable $e) {
                Log::error("monitor: {$name}: " . $e->getMessage());
                $checks[$name] = State::check('unknown', null, 'Measure failed: ' . $e->getMessage(), $frequency);
            }
        }

        return $checks;
    }

    /**
     * @return array<int, string>
     */
    public static function fastChecks(): array
    {
        return [
            'database.reachable', 'dolibarr.version', 'dolibarr.db_version', 'dolibarr.upgrade_pending', 'php.cli_version',
            'php.web_version', 'php.compatibility', 'maintenance.enabled', 'sessions.count', 'cron.failed', 'cron.stale',
            'cron.stuck', 'storage.free_bytes', 'security.install_lock', 'security.login_failures_24h', 'dolicli.operation',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function slowChecks(): array
    {
        return [
            'users.active', 'backup.age', 'storage.documents_bytes', 'storage.database_bytes', 'filecheck.modified',
            'filecheck.missing', 'filecheck.added', 'modules.external', 'security.conf_permissions', 'security.prod_mode',
            'tls.expiry_seconds',
        ];
    }

    /**
     * Operation in progress: instance lock (4.7) or upgrade plan lease.
     *
     * @return array<string, mixed>
     */
    public function operation(): array
    {
        $holder = Lock::forDataRoot($this->dataRoot)->holder();
        if ([] !== $holder) {
            return $holder;
        }
        $lease = (new Workspace($this->dataRoot))->lease();
        if ([] !== $lease) {
            return ['command' => 'upgrade plan', 'started_at' => $lease['started_at'], 'operator' => $lease['holder']];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(string $name, string $frequency, bool $database, string $loadError): array
    {
        switch ($name) {
            case 'database.reachable':
                return $database
                    ? State::check('ok', true, 'Database reachable', $frequency)
                    : State::check('critical', false, 'Database unreachable' . ('' !== $loadError ? ': ' . $loadError : ''), $frequency);
            case 'dolibarr.version':
                $version = $database ? Dolibarr::version() : $this->instance->filesVersion();

                return '' === $version
                    ? State::check('unknown', null, 'Cannot read the version of the files', $frequency)
                    : State::check('ok', $version, 'Dolibarr ' . $version, $frequency);
            case 'dolibarr.db_version':
                $version = Dolibarr::databaseVersion();

                return '' === $version
                    ? State::check('unknown', null, 'MAIN_VERSION_LAST_UPGRADE and MAIN_VERSION_LAST_INSTALL are empty', $frequency)
                    : State::check('ok', $version, 'Database ' . $version, $frequency);
            case 'dolibarr.upgrade_pending':
                return $this->upgradePending($frequency);
            case 'php.cli_version':
                return State::check('ok', PHP_VERSION, 'PHP CLI ' . PHP_VERSION, $frequency);
            case 'php.web_version':
                $web = $this->instance->setting('web_php');

                return '' === $web
                    ? State::check('skipped', null, 'web_php is not declared for this instance', $frequency)
                    : State::check('ok', $web, 'PHP of the web server: ' . $web . ' (declared)', $frequency);
            case 'php.compatibility':
                return $this->phpCompatibility($frequency);
            case 'maintenance.enabled':
                $enabled = '' !== Maintenance::currentLogin();

                return State::check($enabled ? 'warning' : 'ok', $enabled, $enabled ? 'Maintenance mode is on' : 'Maintenance mode is off', $frequency);
            case 'sessions.count':
                return $this->sessions($frequency);
            case 'users.active':
                $count = $this->count('SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'user WHERE statut = 1 AND entity IN (0, ' . $this->entity() . ')');

                return State::check('ok', $count, $count . ' active users', $frequency);
            case 'cron.failed':
                $count = $this->countCronJobs(CronJobs::failedCondition());

                return $this->thresholded('cron.failed', $count, $count . ' active scheduled jobs whose last run failed', $frequency);
            case 'cron.stale':
                $count = $this->countCronJobs(CronJobs::lateCondition(time()));

                return $this->thresholded('cron.stale', $count, $count . ' active scheduled jobs late by more than ' . self::CRON_STALE_MARGIN . ' s', $frequency);
            case 'cron.stuck':
                $count = $this->countCronJobs(CronJobs::stuckCondition(time()));

                return $this->thresholded('cron.stuck', $count, $count . ' scheduled jobs running for more than ' . self::CRON_STUCK_AFTER . ' s', $frequency);
            case 'storage.free_bytes':
                return $this->freeSpace($frequency);
            case 'security.install_lock':
                $locked = is_file($this->dataRoot . '/install.lock') || is_file($this->instance->htdocs() . '/install.lock');

                return State::check($locked ? 'ok' : 'critical', $locked, $locked ? 'install.lock present' : 'install.lock missing: the install pages are open', $frequency);
            case 'security.login_failures_24h':
                return $this->loginFailures($frequency);
            case 'dolicli.operation':
                $operation = $this->operation();

                return [] === $operation
                    ? State::check('ok', null, 'No operation in progress', $frequency)
                    : State::check('ok', $operation, 'Operation in progress: ' . (isset($operation['command']) ? $operation['command'] : '?'), $frequency);
            case 'backup.age':
                return $this->backupAge($frequency);
            case 'storage.documents_bytes':
                return $this->documentsSize($frequency);
            case 'storage.database_bytes':
                return $this->databaseSize($frequency);
            case 'filecheck.modified':
            case 'filecheck.missing':
            case 'filecheck.added':
                return $this->filecheck($name, $frequency);
            case 'modules.external':
                $list = [];
                foreach (Modules::all() as $modName => $module) {
                    if (Modules::isExternal($module) && Modules::isActive($module)) {
                        $list[] = ['module' => $modName, 'version' => (string) $module->getVersion(1)];
                    }
                }

                return State::check('ok', $list, count($list) . ' active external modules', $frequency);
            case 'security.conf_permissions':
                return $this->confPermissions($frequency);
            case 'security.prod_mode':
                $reader = PhpFileReader::fromFile($this->instance->confFile());
                if (null === $reader) {
                    return State::check('unknown', null, 'conf.php unreadable', $frequency);
                }
                $prod = 1 === (int) $reader->variable('dolibarr_main_prod');

                return State::check($prod ? 'ok' : 'warning', $prod, $prod ? 'Production mode on' : '$dolibarr_main_prod is not 1: errors are displayed to users', $frequency);
            case 'tls.expiry_seconds':
                return $this->tlsExpiry($frequency);
        }

        return State::check('unknown', null, 'Unknown check', $frequency);
    }

    /**
     * @param int|float $value
     * @return array<string, mixed>
     */
    private function thresholded(string $name, $value, string $message, string $frequency): array
    {
        list($status, $applied) = $this->thresholds->evaluate($name, $value);

        return State::check($status, $value, $message, $frequency, $applied);
    }

    private function entity(): int
    {
        global $conf;

        return isset($conf->entity) ? (int) $conf->entity : 1;
    }

    private function countCronJobs(string $condition): int
    {
        return $this->count('SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'cronjob WHERE ' . $condition . ' AND ' . CronJobs::entityCondition());
    }

    private function count(string $sql): int
    {
        $db = Dolibarr::db();
        $resql = $db->query($sql);
        if (!$resql) {
            throw new \RuntimeException($db->lasterror());
        }
        $obj = $db->fetch_object($resql);

        return $obj ? (int) $obj->nb : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function upgradePending(string $frequency): array
    {
        $files = Dolibarr::version();
        $database = Dolibarr::databaseVersion();
        if ('' !== Dolibarr::conf('MAIN_NOT_UPGRADED') || '' !== Dolibarr::conf('MAIN_NOT_INSTALLED')) {
            return State::check('critical', true, 'A previous install or upgrade did not complete: the site redirects to the install pages', $frequency);
        }
        if ('' === $database) {
            return State::check('unknown', null, 'Database version unknown', $frequency);
        }
        $compare = MigrationPlanner::compareFilesAndDatabase($files, $database, '' !== Dolibarr::conf('MAIN_NO_UPGRADE_REDIRECT_ON_LEVEL_3_CHANGE'));
        if (1 === $compare) {
            return State::check('critical', true, "Files {$files} newer than the database {$database}: the site redirects to the upgrade page", $frequency);
        }
        if (-1 === $compare) {
            return State::check('critical', false, "Database {$database} newer than the files {$files}", $frequency);
        }

        return State::check('ok', false, 'No upgrade pending', $frequency);
    }

    /**
     * @return array<string, mixed>
     */
    private function phpCompatibility(string $frequency): array
    {
        $web = $this->instance->setting('web_php');
        if ('' === $web) {
            return State::check('unknown', null, 'web_php is not declared: compatibility of the web PHP unknown', $frequency);
        }
        $dolibarr = defined('DOL_VERSION') ? DOL_VERSION : $this->instance->filesVersion();
        $range = Matrix::range($dolibarr);
        $result = Matrix::check($dolibarr, $web);
        $status = Matrix::OK === $result ? 'ok' : (Matrix::WARNING === $result ? 'warning' : (Matrix::INCOMPATIBLE === $result ? 'critical' : 'unknown'));
        $max = '' !== $range['max'] ? $range['max'] : 'unknown';

        return State::check($status, $result, "PHP {$web} with Dolibarr {$dolibarr}: {$result} (range {$range['min']} to {$max})", $frequency);
    }

    /**
     * listOfSessions() reads the sess_* files of session.save_path of the PHP CLI: wrong when the
     * web pool uses another path or Redis/memcached, and 0 without error when the directory cannot
     * be listed (mode 1733 on Debian). The directory is checked first.
     *
     * @return array<string, mixed>
     */
    private function sessions(string $frequency): array
    {
        $handler = (string) ini_get('session.save_handler');
        if ('' !== $handler && 'files' !== $handler) {
            return State::check('unknown', null, "Sessions stored with {$handler}: not countable from files", $frequency);
        }
        $path = (string) ini_get('session.save_path');
        $parts = explode(';', $path);
        $path = '' === $path ? '/tmp' : (string) end($parts);
        $dir = @opendir($path);
        if (false === $dir) {
            return State::check('unknown', null, "Session directory {$path} cannot be listed by " . get_current_user(), $frequency);
        }
        closedir($dir);
        Dolibarr::requireCore('core/lib/admin.lib.php');
        $count = count(listOfSessions());

        return State::check('ok', $count, $count . ' active sessions', $frequency);
    }

    /**
     * @return array<string, mixed>
     */
    private function freeSpace(string $frequency): array
    {
        $free = [];
        foreach ([$this->dataRoot, $this->instance->htdocs()] as $path) {
            $bytes = @disk_free_space($path);
            if (false !== $bytes) {
                $free[$path] = (int) $bytes;
            }
        }
        if ([] === $free) {
            return State::check('unknown', null, 'Cannot read the free space of documents/ and htdocs', $frequency);
        }
        $min = min($free);
        $where = (string) array_search($min, $free, true);

        return $this->thresholded('storage.free_bytes', $min, sprintf('%.1f GiB free on the partition of %s', $min / 1073741824, $where), $frequency);
    }

    /**
     * @return array<string, mixed>
     */
    private function loginFailures(string $frequency): array
    {
        if ('' === Dolibarr::conf('MAIN_LOGEVENTS_USER_LOGIN_FAILED')) {
            return State::check('skipped', null, 'Login failure audit is disabled (MAIN_LOGEVENTS_USER_LOGIN_FAILED)', $frequency);
        }
        $since = Dolibarr::db()->idate(time() - 86400);
        $count = $this->count('SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . "events WHERE type = 'USER_LOGIN_FAILED' AND dateevent >= '" . $since . "'"
            . ' AND entity IN (0, ' . $this->entity() . ')');

        return $this->thresholded('security.login_failures_24h', $count, $count . ' login failures in 24 h', $frequency);
    }

    /**
     * @return array<string, mixed>
     */
    private function backupAge(string $frequency): array
    {
        $dirs = [$this->dataRoot . '/admin/backup'];
        if ('' !== $this->instance->setting('backup_dir')) {
            $dirs[] = $this->instance->setting('backup_dir');
        }
        $pattern = $this->instance->setting('backup_pattern', '*');
        $latest = 0;
        $latestFile = '';
        foreach ($dirs as $dir) {
            $files = glob(rtrim($dir, '/') . '/' . $pattern);
            foreach (is_array($files) ? $files : [] as $file) {
                $mtime = (int) @filemtime($file);
                if ($mtime > $latest) {
                    $latest = $mtime;
                    $latestFile = $file;
                }
            }
        }
        if (0 === $latest) {
            return State::check('critical', null, 'No backup found in ' . implode(', ', $dirs) . ' (pattern ' . $pattern . ')', $frequency, ['critical' => 0]);
        }
        $age = max(0, time() - $latest);

        return $this->thresholded('backup.age', $age, sprintf('Last backup %s, %.1f days ago', basename($latestFile), $age / 86400), $frequency);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentsSize(string $frequency): array
    {
        $deadline = microtime(true) + 120;
        $total = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dataRoot, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                    $total += $file->getSize();
                }
                if (microtime(true) > $deadline) {
                    return State::check('unknown', null, 'documents/ too large to measure within 120 s', $frequency);
                }
            }
        } catch (\Exception $e) {
            return State::check('unknown', null, 'Cannot measure documents/: ' . $e->getMessage(), $frequency);
        }

        return State::check('ok', $total, sprintf('documents/ uses %.1f GiB', $total / 1073741824), $frequency);
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseSize(string $frequency): array
    {
        global $conf;

        $type = (string) $conf->db->type;
        if ('mysqli' === $type || 'mysql' === $type) {
            $size = $this->count('SELECT SUM(data_length + index_length) AS nb FROM information_schema.TABLES WHERE table_schema = DATABASE()');
        } elseif ('pgsql' === $type) {
            $size = $this->count('SELECT pg_database_size(current_database()) AS nb');
        } elseif ('sqlite3' === $type) {
            $file = DOL_DATA_ROOT . '/database_' . $conf->db->name . '.sdb';
            $size = (int) @filesize($file);
        } else {
            return State::check('unknown', null, "Database type {$type} not supported", $frequency);
        }

        return State::check('ok', $size, sprintf('Database uses %.1f MiB', $size / 1048576), $frequency);
    }

    /**
     * Local signature only: the monitored server often has no outgoing access.
     *
     * @return array<string, mixed>
     */
    private function filecheck(string $name, string $frequency): array
    {
        static $result = null;
        if (null === $result) {
            if (!is_file(Filecheck::localSignature())) {
                $result = [];
            } else {
                $result = Filecheck::run(false, '', true);
            }
        }
        if ([] === $result) {
            return State::check('unknown', null, 'No local signature ' . basename(Filecheck::localSignature()) . ' (and no outgoing access assumed)', $frequency);
        }
        $key = ['filecheck.modified' => 'updated', 'filecheck.missing' => 'missing', 'filecheck.added' => 'added'][$name];
        $count = count($result[$key]);
        $label = ['updated' => 'modified', 'missing' => 'missing', 'added' => 'added'][$key];

        return $this->thresholded($name, $count, $count . ' ' . $label . ' files against ' . basename($result['source']), $frequency);
    }

    /**
     * @return array<string, mixed>
     */
    private function confPermissions(string $frequency): array
    {
        $file = $this->instance->confFile();
        $perms = @fileperms($file);
        if (false === $perms) {
            return State::check('unknown', null, "Cannot read the permissions of {$file}", $frequency);
        }
        $mode = sprintf('%o', $perms & 0777);
        if ($perms & 0002) {
            return State::check('critical', $mode, "{$file} is writable by everyone", $frequency);
        }
        if ($perms & 0004) {
            return State::check('warning', $mode, "{$file} is readable by everyone (database password)", $frequency);
        }

        return State::check('ok', $mode, "{$file} not readable by other accounts", $frequency);
    }

    /**
     * Disabled by default: seen from the server itself it often fails (hairpin NAT, internal URL,
     * no outgoing access). Enable with tls.enabled = 1 in thresholds.ini.
     *
     * @return array<string, mixed>
     */
    private function tlsExpiry(string $frequency): array
    {
        if ('1' !== $this->thresholds->option('tls.enabled')) {
            return State::check('skipped', null, 'TLS check disabled (tls.enabled = 1 in thresholds.ini)', $frequency);
        }
        $reader = PhpFileReader::fromFile($this->instance->confFile());
        $url = null === $reader ? '' : (string) $reader->variable('dolibarr_main_url_root');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || 'https' !== strtolower($parts['scheme'])) {
            return State::check('skipped', null, 'URL is not https: ' . $url, $frequency);
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_server_name' => $parts['host']]]);
        $socket = @stream_socket_client('ssl://' . $parts['host'] . ':' . $port, $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if (false === $socket) {
            return State::check('unknown', null, "Cannot connect to {$parts['host']}:{$port}: {$error}", $frequency);
        }
        $params = stream_context_get_params($socket);
        fclose($socket);
        $cert = isset($params['options']['ssl']['peer_certificate']) ? openssl_x509_parse($params['options']['ssl']['peer_certificate']) : false;
        if (!is_array($cert) || !isset($cert['validTo_time_t'])) {
            return State::check('unknown', null, 'Cannot read the certificate of ' . $parts['host'], $frequency);
        }
        $remaining = (int) $cert['validTo_time_t'] - time();

        return $this->thresholded('tls.expiry_seconds', $remaining, sprintf('Certificate of %s expires in %.0f days', $parts['host'], $remaining / 86400), $frequency);
    }

    /**
     * Instance description of the state file.
     *
     * @return array<string, mixed>
     */
    public function instanceInfo(): array
    {
        $reader = PhpFileReader::fromFile($this->instance->confFile());
        $url = null === $reader ? null : $reader->variable('dolibarr_main_url_root');

        return ['id' => $this->instance->id(), 'htdocs' => $this->instance->htdocs(), 'url' => is_string($url) && '' !== $url ? $url : null];
    }
}
