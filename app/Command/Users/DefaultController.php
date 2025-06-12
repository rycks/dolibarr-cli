<?php

declare(strict_types=1);

namespace App\Command\Users;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $this->info("All of dolibarr cli for user manipulation
Usage:
 - dolibarr users password --help
 - dolibarr users list --help");
    }
}
