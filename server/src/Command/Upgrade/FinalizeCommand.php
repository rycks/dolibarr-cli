<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Upgrade\MigrationPlanner;
use Dolicli\Upgrade\Migrator;
use Dolicli\Upgrade\Workspace;

final class FinalizeCommand extends Command
{
    public function name(): string
    {
        return 'upgrade finalize';
    }

    public function summary(): string
    {
        return 'Finish an upgrade: step5.php, install.lock put back, upgrade.unlock removed';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [Param::value('from', 'X.Y.Z', 'Version the database came from (default: the version before the migration recorded in the plan)')->matching('/^\d+\.\d+\.\d+/')];
    }

    public function help(): string
    {
        return 'Run it after dolicli upgrade migrate, also after a failed migration: it always puts install.lock back.';
    }

    public function execute(Context $ctx): array
    {
        $workspace = Workspace::current();
        $plan = $workspace->plan();
        $from = (string) $ctx->value('from');
        if ('' === $from && isset($plan['from']) && is_string($plan['from'])) {
            $from = $plan['from'];
        }
        if ('' === $from) {
            $from = Dolibarr::databaseVersion();
        }
        $to = MigrationPlanner::clean($ctx->instance()->filesVersion());

        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ["Run install/step5.php upgrade {$from} -> {$to} with installlock", 'Check install.lock, remove upgrade.unlock']];
        }
        $migrator = new Migrator(function ($text) use ($ctx) {
            $ctx->progress(rtrim($text, "\n"));
        }, $workspace);
        $migrator->finalize(DOL_DOCUMENT_ROOT, MigrationPlanner::clean($from), $to);

        return ['from' => $from, 'to' => $to, 'install_lock' => true];
    }

    public function renderText(array $data): string
    {
        return "Upgrade finalized ({$data['from']} -> {$data['to']}): install.lock in place.\n";
    }
}
