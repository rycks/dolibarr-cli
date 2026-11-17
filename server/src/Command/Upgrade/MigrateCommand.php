<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Upgrade\MigrationPlanner;
use Dolicli\Upgrade\Migrator;
use Dolicli\Upgrade\Workspace;

/**
 * Migrate the database to the version of the files on disk, one major version at a time.
 */
final class MigrateCommand extends Command
{
    public function name(): string
    {
        return 'upgrade migrate';
    }

    public function summary(): string
    {
        return 'Migrate the database to the version of the files (upgrade.php, upgrade2.php per step)';
    }

    public function category(): string
    {
        return self::DESTRUCTIVE;
    }

    public function params(): array
    {
        return [
            Param::value('from', 'X.Y.Z', 'Version the database comes from (default: MAIN_VERSION_LAST_UPGRADE)')->matching('/^\d+\.\d+\.\d+/'),
            Param::flag('force', 'Run the step of the current branch again even if the database looks up to date'),
        ];
    }

    public function help(): string
    {
        return "Every step runs in a separate PHP process (the one running dolicli: use the PHP CLI of the\n"
            . "target version). The progress is recorded in documents/dolicli/upgrade/plan.json when a plan exists.\n"
            . 'install.lock is put back at the end, even after a failure. Then: dolicli upgrade finalize.';
    }

    public function execute(Context $ctx): array
    {
        $from = (string) $ctx->value('from');
        if ('' === $from) {
            $from = Dolibarr::databaseVersion();
        }
        if (!MigrationPlanner::isVersion($from)) {
            throw Failure::operation('UNKNOWN_DATABASE_VERSION', 'Cannot tell which version the database comes from (MAIN_VERSION_LAST_UPGRADE is empty): give it with from=X.Y.Z.');
        }
        $from = MigrationPlanner::clean($from);
        $to = MigrationPlanner::clean($ctx->instance()->filesVersion());
        $steps = Migrator::plan(DOL_DOCUMENT_ROOT, $from, $to, $ctx->flag('force'));

        if ($ctx->dryRun()) {
            $actions = [];
            foreach ($steps as $step) {
                $actions[] = "Run install/upgrade.php then upgrade2.php {$step['from']} -> {$step['to']}";
            }

            return ['dry_run' => true, 'actions' => [] === $actions ? ["Nothing: the database is already at {$from}"] : $actions];
        }

        $migrator = new Migrator(function ($text) use ($ctx) {
            $ctx->progress(rtrim($text, "\n"));
        }, Workspace::current());
        $done = $migrator->migrate(DOL_DOCUMENT_ROOT, $from, $to, $ctx->flag('force'));

        return ['from' => $from, 'to' => $to, 'steps' => $done, 'migrated' => [] !== $done];
    }

    public function renderText(array $data): string
    {
        if (!$data['migrated']) {
            return "Database is already at version {$data['from']}: nothing to migrate (use --force to run it again).\n";
        }

        return "Database migrated from {$data['from']} to {$data['to']} (" . count($data['steps']) . " step(s)).\nNext: dolicli upgrade finalize from={$data['from']}\n";
    }
}
