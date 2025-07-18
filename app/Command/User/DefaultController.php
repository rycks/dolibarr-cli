<?php

declare(strict_types=1);

namespace App\Command\User;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $cmd = implode(" ",$this->getArgs());

        $this->info("All of dolibarr cli for user manipulation
Usage:
 - $cmd password --help
 - $cmd edit --help
 - $cmd list --help");
    }
}
