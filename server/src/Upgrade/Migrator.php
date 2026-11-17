<?php

namespace Dolicli\Upgrade;

use Dolicli\Cli\Failure;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\System\Log;

/**
 * Database migration with Dolibarr's own scripts, one step x.y.z -> a.b.c at a time
 * (upgrade.php then upgrade2.php), then step5.php. Each script runs in a separate PHP
 * process; the install pages are opened through upgrade.unlock only for that time, and
 * install.lock is always put back, even after a failure.
 */
final class Migrator
{
    /** @var callable */
    private $progress;

    /** @var Workspace */
    private $workspace;

    /** @var string */
    private $php;

    /**
     * @param callable $progress Receives progress lines
     * @param string $php PHP binary for the install scripts (default: the one running dolicli)
     */
    public function __construct(callable $progress, Workspace $workspace, string $php = '')
    {
        $this->progress = $progress;
        $this->workspace = $workspace;
        $this->php = $php;
    }

    /**
     * @return array<int, array{from: string, to: string}>
     */
    public static function plan(string $htdocs, string $from, string $to, bool $force): array
    {
        return MigrationPlanner::plan(MigrationPlanner::migrationFiles($htdocs . '/install/mysql/migration'), $from, $to, $force);
    }

    /**
     * Run the migration steps from $from to the version of the files $to.
     *
     * @return array<int, array{from: string, to: string}> Steps run
     */
    public function migrate(string $htdocs, string $from, string $to, bool $force): array
    {
        $installDir = $htdocs . '/install';
        foreach (['upgrade.php', 'upgrade2.php', 'step5.php'] as $script) {
            if (!is_file($installDir . '/' . $script)) {
                throw Failure::operation('INSTALL_SCRIPT_MISSING', "Upgrade file not found: {$installDir}/{$script}");
            }
        }
        $steps = self::plan($htdocs, $from, $to, $force);
        if ([] === $steps) {
            return [];
        }

        $lock = new InstallLock(DOL_DATA_ROOT, $htdocs);
        $lock->open();
        $runner = new InstallScriptRunner($this->progress, $this->php);
        try {
            $total = count($steps);
            foreach ($steps as $index => $step) {
                $id = 'migration ' . $step['from'] . '-' . $step['to'];
                $number = $index + 1;
                $this->say("Step {$number}/{$total}: SQL migration {$step['from']} -> {$step['to']}");
                $this->workspace->recordStep($id, 'running');
                $args = [$step['from'], $step['to']];
                if ($force) {
                    $args[] = 'ignoredbversion';
                }
                $this->runScript($runner, $installDir, 'upgrade.php', $args, $id);

                $this->say("Step {$number}/{$total}: data migration {$step['from']} -> {$step['to']}");
                $this->runScript($runner, $installDir, 'upgrade2.php', [$step['from'], $step['to']], $id);
                $this->workspace->recordStep($id, 'done');
            }
        } finally {
            $lock->close();
        }

        return $steps;
    }

    /**
     * step5.php in upgrade mode with installlock, then check that install.lock is there
     * and upgrade.unlock gone.
     */
    public function finalize(string $htdocs, string $from, string $to)
    {
        $lock = new InstallLock(DOL_DATA_ROOT, $htdocs);
        $lock->open();
        $runner = new InstallScriptRunner($this->progress, $this->php);
        try {
            $lang = Dolibarr::conf('MAIN_LANG_DEFAULT', 'en_US');
            if ('auto' === $lang) {
                $lang = 'en_US';
            }
            $this->say('Finalizing (step5.php)');
            $this->workspace->recordStep('finalize', 'running');
            // action 'upgrade' (not 'set', for fresh installs, which does not update MAIN_VERSION_LAST_UPGRADE);
            // the last argument (installlock) makes step5 create install.lock.
            $this->runScript($runner, $htdocs . '/install', 'step5.php', [$from, $to, $lang, 'upgrade', '', '', '', '1'], 'finalize');
        } finally {
            $lock->close();
        }
        if (!$lock->isLocked() || is_file($lock->unlockFile())) {
            Log::error('finalize: install.lock missing or upgrade.unlock still present');
            throw Failure::operation('INSTALL_LOCK_MISSING', 'install.lock could not be put back: the install pages may be open, create ' . DOL_DATA_ROOT . '/install.lock.');
        }
        $this->workspace->recordStep('finalize', 'done');
    }

    /**
     * @param array<int, string> $args
     */
    private function runScript(InstallScriptRunner $runner, string $installDir, string $script, array $args, string $stepId)
    {
        $code = $runner->run($installDir, $script, $args);
        if (0 !== $code) {
            $stderr = trim($runner->getStderr());
            $message = "{$script} failed (exit code {$code})" . ('' !== $stderr ? ': ' . substr($stderr, 0, 500) : '')
                . '. The database may be in an inconsistent state: check it or restore the backup.';
            $this->workspace->recordStep($stepId, 'failed', $message);
            throw Failure::operation('MIGRATION_FAILED', $message);
        }
    }

    private function say(string $line)
    {
        call_user_func($this->progress, $line . "\n");
    }
}
