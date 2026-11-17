<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Support\Command;

class DefaultController extends Command
{
    public function handle(): void
    {
        $this->info('Dolibarr system commands

Usage:
  ./dolibarr system upgrade [--check|--list|--minor|--major|--version=X.Y.Z] - Upgrade
  ./dolibarr system maintenance enable|disable|status                         - Maintenance mode
  ./dolibarr system cronkey show|set|generate|clear                          - Cron security key
  ./dolibarr system filecheck [--remote] [--format=json]                     - File integrity

Examples:
  ./dolibarr system upgrade --check
  ./dolibarr system upgrade                   - Database migration only
  ./dolibarr system maintenance status
  ./dolibarr system cronkey generate
  ./dolibarr system filecheck --remote

Add --help to any command for its options.');
    }
}
