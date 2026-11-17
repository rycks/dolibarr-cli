<?php

declare(strict_types=1);

namespace App\Command\Thirdparty;

use App\Support\Command;

class DefaultController extends Command
{
    public function handle(): void
    {
        $this->info('Dolibarr third party management

Usage:
  ./dolibarr thirdparty list [type=...] [search=...]   - List third parties
  ./dolibarr thirdparty accountingnumber [--confirm]   - Recompute customer accounting codes

Add --help to any command for its options.');
    }
}
