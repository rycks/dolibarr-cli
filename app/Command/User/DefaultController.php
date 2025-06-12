<?php

declare(strict_types=1);

namespace App\Command\User;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $this->info("All of dolibarr cli for user manipulation
Usage:
 - dolibarr user password --help
 - dolibarr user edit --help
 - dolibarr user list --help");
    }
}
