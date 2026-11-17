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
        ];
    }
}
