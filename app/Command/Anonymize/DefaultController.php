<?php

declare(strict_types=1);

namespace App\Command\Anonymize;

use App\Support\Command;

class DefaultController extends Command
{
    public function handle(): void
    {
        $this->info('Anonymize all personal data in the Dolibarr database

Usage:
  ./dolibarr anonymize run [options]

Options:
'.RunController::OPTIONS_HELP.'

Examples:
  ./dolibarr anonymize run                                    Show what would be anonymized
  ./dolibarr anonymize run --confirm --this-is-a-copy         Execute full anonymization
  ./dolibarr anonymize run --confirm --this-is-a-copy --skip-bank
  ./dolibarr anonymize run --confirm --this-is-a-copy keep-admin=admin');
    }
}
