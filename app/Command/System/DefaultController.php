<?php

declare(strict_types=1);

namespace App\Command\System;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $cmd = implode(" ", $this->getArgs());

        $this->info("Dolibarr CLI system commands

Usage:

  $cmd upgrade                          - Run database migration only
  $cmd upgrade --minor                  - Upgrade to latest minor version
  $cmd upgrade --major                  - Upgrade to latest major version
  $cmd upgrade --version=X.Y.Z          - Upgrade to specific version
  $cmd upgrade --check                  - Check for available upgrades
  $cmd upgrade --list                   - List available versions
  $cmd upgrade --help                   - Show detailed upgrade help

  $cmd maintenance enable [login=user]  - Enable maintenance mode
  $cmd maintenance disable              - Disable maintenance mode
  $cmd maintenance status               - Show maintenance status

  $cmd cronkey show                     - Show scheduled jobs security key
  $cmd cronkey set key=XXXX             - Overwrite CRON_KEY
  $cmd cronkey generate                 - Generate a random CRON_KEY
  $cmd cronkey clear                    - Remove CRON_KEY

  $cmd filecheck                        - Verify deployment file integrity
  $cmd filecheck --detect-extra         - Also list files not in signature
  $cmd filecheck --format=json          - JSON output
  $cmd filecheck --xmlfile=PATH         - Use a specific signature XML
  $cmd filecheck --help                 - Show detailed filecheck help

Examples:
  $cmd upgrade                          - Database migration only
  $cmd upgrade --check                  - Check for upgrades
  $cmd upgrade --minor                  - Full upgrade to latest minor
  $cmd maintenance status               - Check maintenance mode
  $cmd cronkey show                     - Display current CRON_KEY
  $cmd cronkey generate                 - Generate a new CRON_KEY
  $cmd filecheck                        - Check deployment integrity");
    }
}
