<?php

declare(strict_types=1);

namespace App\Command\Repair;

use App\Support\Command;

class DefaultController extends Command
{
    public function handle(): void
    {
        $this->info('Dolibarr database repair and maintenance commands

Usage:
  ./dolibarr repair run --help          - Display repair command help
  ./dolibarr repair run action=ACTION   - Execute a specific repair action

Available actions:
'.RunController::actionsHelp('  ').'

Example:
  ./dolibarr repair run action=clean_menus mode=confirmed');
    }
}
