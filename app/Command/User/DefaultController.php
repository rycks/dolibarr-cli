<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Support\Command;

class DefaultController extends Command
{
    public function handle(): void
    {
        $this->info('Dolibarr user management

Usage:
  ./dolibarr user list                                   - List users
  ./dolibarr user show login=LOGIN                       - Display user details
  ./dolibarr user add login=LOGIN firstname=F lastname=L - Create a user
  ./dolibarr user password login=LOGIN                   - Change a password
  ./dolibarr user edit login=LOGIN --lock|--unlock       - Disable or enable an account
  ./dolibarr user token login=LOGIN                      - Generate a REST API key

Add --help to any command for its options.');
    }
}
