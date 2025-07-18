<?php

declare(strict_types=1);

namespace App\Command\Module;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $cmd = implode(" ",$this->getArgs());

        $this->info("All of dolibarr cli for modules manipulation
Usage:
 - $cmd list --help
 - $cmd activate --help
 - $cmd deactivate --help");
    }
}
