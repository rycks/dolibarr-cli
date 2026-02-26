<?php

declare(strict_types=1);

namespace App\Command\Anonymize;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $cmd = implode(" ", $this->getArgs());

        $this->info("Anonymize all personal data in the Dolibarr database

Usage:
  {$cmd} run [options]

Options:
  --confirm            Execute the anonymization (required, otherwise dry-run)
  --skip-thirdparties  Skip third parties and contacts
  --skip-users         Skip user accounts
  --skip-members       Skip members (adherents)
  --skip-bank          Skip bank/payment data
  keep-admin=LOGIN     Admin login to preserve (default: first super admin)

Examples:
  ./dolibarr anonymize run                          Show what would be anonymized
  ./dolibarr anonymize run --confirm                Execute full anonymization
  ./dolibarr anonymize run --confirm --skip-bank    Skip bank data
  ./dolibarr anonymize run --confirm keep-admin=admin");
    }
}
