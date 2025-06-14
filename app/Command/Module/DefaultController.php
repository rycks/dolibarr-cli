<?php

declare(strict_types=1);

namespace App\Command\Module;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $this->info("All of dolibarr cli for modules manipulation
Usage:
 - dolibarr module list --help
 - dolibarr module activate --help
 - dolibarr module deactivate --help");
    }
}
