<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Util\DolibarrHelper;
use App\Support\Command;
use User;

class EditController extends Command
{
    public function summary(): string
    {
        return 'Lock or unlock a user account';
    }

    public function usage(): void
    {
        $this->info('Lock or unlock a Dolibarr user account

Usage:
  ./dolibarr user edit login=LOGIN --lock
  ./dolibarr user edit login=LOGIN --unlock

Options:
  --lock    - Disable the account
  --unlock  - Enable the account
  --help    - Display this help message

To change a password, use ./dolibarr user password.');
    }

    public function handle(): void
    {
        global $db;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        $login = $this->param('login');
        $lock = $this->hasFlag('lock');
        $unlock = $this->hasFlag('unlock');
        if (null === $login || '' === $login || $lock === $unlock) {
            $this->usage();
            $this->fail('Give login=LOGIN and exactly one of --lock, --unlock.');

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        DolibarrHelper::requireCore('user/class/user.class.php');
        $u = new User($db);
        $res = $u->fetch(0, $login);
        if ($res <= 0) {
            $this->fail(0 === $res ? "User '{$login}' not found." : "Cannot fetch user '{$login}': ".$u->error);

            return;
        }

        $result = $u->setstatus($lock ? User::STATUS_DISABLED : User::STATUS_ENABLED);
        if ($result < 0) {
            $this->fail("User '{$login}' not updated: ".$u->error);

            return;
        }

        $this->success("User '{$login}' is now ".($lock ? 'disabled' : 'enabled').'.');
    }
}
