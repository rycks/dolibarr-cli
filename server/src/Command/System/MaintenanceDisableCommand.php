<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Dolibarr\Maintenance;

final class MaintenanceDisableCommand extends Command
{
    public function name(): string
    {
        return 'system maintenance disable';
    }

    public function summary(): string
    {
        return 'Disable the maintenance mode: every user may connect again';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function execute(Context $ctx): array
    {
        if ('' === Maintenance::currentLogin()) {
            return ['enabled' => false, 'changed' => false];
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Disable the maintenance mode']];
        }
        if (!Maintenance::forceDisable()) {
            throw Failure::operation('MAINTENANCE_FAILED', 'Failed to disable the maintenance mode (details in the log).');
        }

        return ['enabled' => false, 'changed' => true];
    }

    public function renderText(array $data): string
    {
        return $data['changed'] ? "Maintenance mode DISABLED: all users can log in.\n" : "Maintenance mode was not active.\n";
    }
}
