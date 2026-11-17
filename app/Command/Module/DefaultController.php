<?php

declare(strict_types=1);

namespace App\Command\Module;

use App\Support\Command;

class DefaultController extends Command
{
    public function handle(): void
    {
        $this->info('Dolibarr module management

Usage:
  ./dolibarr module list [--active|--inactive]   - List installed modules
  ./dolibarr module activate name=MODULE         - Activate a module
  ./dolibarr module deactivate name=MODULE       - Deactivate a module

Add --help to any command for its options.');
    }
}
