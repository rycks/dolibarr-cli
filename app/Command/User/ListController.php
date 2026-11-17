<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Util\DolibarrHelper;
use App\Support\Command;
use User;

class ListController extends Command
{
    public function summary(): string
    {
        return 'List users';
    }

    public function usage(): void
    {
        $this->info('List Dolibarr users

Usage:
  ./dolibarr user list

Columns: login, last name, first name, email, status (enabled/disabled),
admin (no, admin, superadmin).');
    }

    public function handle(): void
    {
        global $db;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        DolibarrHelper::requireCore('user/class/user.class.php');
        $users = new User($db);
        $res = $users->fetchAll('ASC', 't.login');
        if ($res < 0) {
            $this->fail("Can't fetch the user list: ".$users->error);

            return;
        }

        $content = [['login', 'lastname', 'firstname', 'email', 'status', 'admin']];
        foreach ($users->users as $u) {
            $content[] = [
                (string) $u->login,
                (string) $u->lastname,
                (string) $u->firstname,
                (string) $u->email,
                1 === (int) $u->statut ? 'enabled' : 'disabled',
                1 !== (int) $u->admin ? 'no' : (0 === (int) $u->entity ? 'superadmin' : 'admin'),
            ];
        }

        $this->printTable($content);
    }
}
