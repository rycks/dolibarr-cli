<?php

declare(strict_types=1);

namespace App\Command\Thirdparty;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $cmd = implode(" ",$this->getArgs());

        $this->info("All of dolibarr cli for thirdparty manipulation
Usage:
 - $cmd accountingnumber --help
 - $cmd list --help");
    }
}
