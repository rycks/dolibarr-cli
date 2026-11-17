<?php

namespace Dolicli\Command\Env;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Text;
use Dolicli\Instance\Instance;
use Dolicli\Instance\PhpFileReader;
use Dolicli\Instance\Resolver;
use Dolicli\System\Mounts;
use Dolicli\System\Paths;
use Dolicli\System\Posix;
use Dolicli\Version;

/**
 * Check the environment without loading Dolibarr: PHP, extensions, account, access to
 * conf.php and documents/, NFS, rights of the installed code.
 */
final class DoctorCommand extends Command
{
    const DB_EXTENSIONS = ['mysqli' => 'mysqli', 'pgsql' => 'pgsql', 'sqlite3' => 'sqlite3'];

    /** @var array<int, array<string, string>> */
    private $checks = [];

    public function name(): string
    {
        return 'env doctor';
    }

    public function summary(): string
    {
        return 'Check PHP, extensions, account, access to conf.php and documents/, NFS, installed code';
    }

    public function needsDolibarr(): bool
    {
        return false;
    }

    public function allowedAsRoot(): bool
    {
        return true;
    }

    public function help(): string
    {
        return "Each check is ok, warning or error. Any error sets exit code 1.\n"
            . 'Without instance (--instance, --dolpath, DOLPATH, ./htdocs), only PHP and the account are checked.';
    }

    public function execute(Context $ctx): array
    {
        $this->checkPhp();
        $this->checkAccount();

        $instance = Resolver::tryResolve($ctx->value('instance'), $ctx->value('dolpath'));
        if (null === $instance) {
            $this->add('instance', 'warning', '', 'No Dolibarr found (--instance, --dolpath, DOLPATH, ./htdocs): instance checks skipped');
        } else {
            $ctx->setInstance($instance);
            $this->checkInstance($instance);
        }
        $this->checkInstalledCode();

        foreach ($this->checks as $check) {
            if ('error' === $check['status']) {
                $ctx->addError('ENV_' . strtoupper(str_replace('.', '_', $check['check'])), $check['message']);
            }
        }

        return ['checks' => $this->checks];
    }

    public function renderText(array $data): string
    {
        $rows = [];
        foreach ($data['checks'] as $check) {
            $rows[] = [$check['status'], $check['check'], $check['message']];
        }

        return Text::table(['Status', 'Check', 'Detail'], $rows);
    }

    private function add(string $check, string $status, string $value, string $message)
    {
        $this->checks[] = ['check' => $check, 'status' => $status, 'value' => $value, 'message' => $message];
    }

    private function checkPhp()
    {
        $ok = version_compare(PHP_VERSION, Version::MIN_PHP, '>=');
        $this->add('php.version', $ok ? 'ok' : 'error', PHP_VERSION, 'PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ')' . ($ok ? '' : ', dolicli needs ' . Version::MIN_PHP));
        $this->add('php.sapi', 'cli' === PHP_SAPI ? 'ok' : 'error', PHP_SAPI, 'SAPI ' . PHP_SAPI);
        $this->add('php.json', function_exists('json_encode') ? 'ok' : 'error', '', function_exists('json_encode') ? 'json extension present' : 'json extension missing (php-json package)');
        $this->add(
            'php.posix',
            Posix::hasExtension() ? 'ok' : 'warning',
            '',
            Posix::hasExtension() ? 'posix extension present' : 'posix extension missing: identities read in /proc and /etc/passwd'
        );
        foreach (['mbstring', 'simplexml', 'zip', 'curl', 'openssl'] as $extension) {
            $loaded = extension_loaded($extension);
            $this->add('php.' . $extension, $loaded ? 'ok' : 'warning', '', $extension . ($loaded ? ' present' : ' missing (some Dolibarr features or dolicli commands need it)'));
        }
    }

    private function checkAccount()
    {
        $name = Posix::currentUserName();
        $this->add('account.user', Posix::isRoot() ? 'warning' : 'ok', $name, 'Running as ' . $name . (Posix::isRoot() ? ': commands that load Dolibarr refuse root without --allow-root' : ''));
        if ('' !== Posix::sudoUser()) {
            $this->add('account.sudo', 'ok', Posix::sudoUser(), 'Called through sudo by ' . Posix::sudoUser());
        }
    }

    private function checkInstance(Instance $instance)
    {
        $htdocs = $instance->htdocs();
        $this->add('instance.htdocs', 'ok', $htdocs, 'Instance ' . $instance->id() . ' (' . $instance->source() . '): ' . $htdocs);
        $version = $instance->filesVersion();
        $this->add('instance.version', '' !== $version ? 'ok' : 'warning', $version, '' !== $version ? 'Dolibarr files ' . $version : 'Cannot read DOL_VERSION in filefunc.inc.php');

        $confFile = $instance->confFile();
        $reader = PhpFileReader::fromFile($confFile);
        if (null === $reader) {
            $this->add('conf.readable', 'error', $confFile, 'Cannot read ' . $confFile . ' as ' . Posix::currentUserName());

            return;
        }
        $this->add('conf.readable', 'ok', $confFile, $confFile . ' readable');
        $perms = @fileperms($confFile);
        if (false !== $perms && ($perms & 0002)) {
            $this->add('conf.permissions', 'error', sprintf('%o', $perms & 0777), $confFile . ' is writable by everyone');
        } elseif (false !== $perms && ($perms & 0004)) {
            $this->add('conf.permissions', 'warning', sprintf('%o', $perms & 0777), $confFile . ' is readable by everyone (database password)');
        } else {
            $this->add('conf.permissions', 'ok', false === $perms ? '' : sprintf('%o', $perms & 0777), 'conf.php not readable by other accounts');
        }

        $dbType = (string) $reader->variable('dolibarr_main_db_type');
        if (array_key_exists($dbType, self::DB_EXTENSIONS)) {
            $ext = self::DB_EXTENSIONS[$dbType];
            $this->add('php.' . $ext, extension_loaded($ext) ? 'ok' : 'error', $dbType, 'Database ' . $dbType . ': extension ' . $ext . (extension_loaded($ext) ? ' present' : ' missing'));
        }

        $dataRoot = $reader->variable('dolibarr_main_data_root');
        if (!is_string($dataRoot) || '' === $dataRoot) {
            $this->add('documents.path', 'warning', '', 'Cannot read dolibarr_main_data_root in conf.php');

            return;
        }
        $real = realpath($dataRoot);
        if (false === $real || !is_dir($real)) {
            $this->add('documents.path', 'error', $dataRoot, 'documents directory not found: ' . $dataRoot);

            return;
        }
        $owner = Posix::ownerName($real);
        $this->add('documents.path', 'ok', $real, 'documents: ' . $real . ' (owner ' . $owner . ', group ' . Posix::groupOwnerName($real) . ')');
        if ('root' === $owner) {
            $this->add('documents.owner', 'warning', $owner, 'documents/ belongs to root: run_as cannot be deduced, set it explicitly');
        }
        $runAs = $instance->setting('run_as');
        if ('' !== $runAs && $runAs !== $owner) {
            $this->add('documents.run_as', 'warning', $runAs, "run_as is {$runAs} but documents/ belongs to {$owner}");
        }
        $account = Posix::currentUserName();
        $writable = is_writable($real);
        $message = $writable ? 'documents/ writable by ' . $account : 'documents/ not writable by ' . $account . ': run as ' . $owner;
        $this->add('documents.writable', $writable ? 'ok' : 'error', '', $message);

        $mount = Mounts::of($real);
        if (null !== $mount && Mounts::isNetwork($mount['type'])) {
            $message = 'documents/ is on ' . $mount['type'] . ' (' . $mount['mount_point'] . '): flock() is not reliable,'
                . ' the instance lock may not protect concurrent operations';
            $this->add('documents.mount', 'warning', $mount['type'], $message);
        } elseif (null !== $mount) {
            $this->add('documents.mount', 'ok', $mount['type'], 'documents/ on ' . $mount['type']);
        }
    }

    private function checkInstalledCode()
    {
        foreach ([Paths::installedLauncher(), Paths::installedBody()] as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $perms = (int) @fileperms($file);
            $owner = Posix::ownerName($file);
            $dirPerms = (int) @fileperms(dirname($file));
            $problems = [];
            if ('root' !== $owner) {
                $problems[] = 'owned by ' . $owner . ', not root';
            }
            if ($perms & 0022) {
                $problems[] = 'writable by group or others';
            }
            if ($dirPerms & 0022) {
                $problems[] = 'its directory is writable by group or others';
            }
            $this->add(
                'installed.' . basename($file),
                [] === $problems ? 'ok' : 'error',
                $file,
                $file . ([] === $problems ? ' owned by root, not writable by other accounts' : ': ' . implode(', ', $problems) . ' (root runs this code)')
            );
        }
    }
}
