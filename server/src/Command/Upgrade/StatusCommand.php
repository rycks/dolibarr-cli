<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Dolibarr\Maintenance;
use Dolicli\Upgrade\Deployer;
use Dolicli\Upgrade\InstallLock;
use Dolicli\Upgrade\MigrationPlanner;
use Dolicli\Upgrade\Migrator;
use Dolicli\Upgrade\Workspace;

/**
 * State of an upgrade: versions, pending migration, locks, prepared and previous trees, plan.
 */
final class StatusCommand extends Command
{
    public function name(): string
    {
        return 'upgrade status';
    }

    public function summary(): string
    {
        return 'State of an upgrade: versions, pending migration steps, locks, prepared files, plan';
    }

    public function execute(Context $ctx): array
    {
        $files = $ctx->instance()->filesVersion();
        $database = Dolibarr::databaseVersion();
        $ignorePatch = '' !== Dolibarr::conf('MAIN_NO_UPGRADE_REDIRECT_ON_LEVEL_3_CHANGE');
        $compare = '' === $database ? 0 : MigrationPlanner::compareFilesAndDatabase($files, $database, $ignorePatch);
        $lock = InstallLock::forCurrentInstall();
        $deployer = Deployer::current();
        $workspace = Workspace::current();
        $steps = '' === $database ? [] : Migrator::plan(DOL_DOCUMENT_ROOT, $database, $files, false);
        $plan = $workspace->plan();
        $lease = $workspace->lease();

        return [
            'files_version' => $files,
            'database_version' => $database,
            'upgrade_pending' => 1 === $compare || '' !== Dolibarr::conf('MAIN_NOT_UPGRADED'),
            'database_newer_than_files' => -1 === $compare,
            'migration_steps' => $steps,
            'install_lock' => $lock->isLocked(),
            'upgrade_unlock' => is_file($lock->unlockFile()),
            'maintenance_login' => '' === Maintenance::currentLogin() ? null : Maintenance::currentLogin(),
            'staged' => $deployer->stagedTrees(),
            'previous' => $deployer->previousTrees(),
            'switch_blockers' => $deployer->switchBlockers(),
            'plan' => [] === $plan ? null : $plan,
            'lease' => [] === $lease ? null : $lease,
            'php_cli' => PHP_VERSION,
            'free_bytes' => [
                'htdocs_parent' => (int) @disk_free_space(dirname(DOL_DOCUMENT_ROOT)),
                'documents' => (int) @disk_free_space(DOL_DATA_ROOT),
            ],
            'htdocs_bytes' => self::size(DOL_DOCUMENT_ROOT),
        ];
    }

    /**
     * Size of a tree (symbolic links not followed), bounded to 60 s; -1 when too long.
     */
    private static function size(string $dir): int
    {
        $deadline = microtime(true) + 60;
        $total = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && !$file->isLink()) {
                    $total += $file->getSize();
                }
                if (microtime(true) > $deadline) {
                    return -1;
                }
            }
        } catch (\Exception $e) {
            return -1;
        }

        return $total;
    }
}
