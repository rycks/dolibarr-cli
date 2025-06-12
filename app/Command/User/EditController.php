<?php

declare(strict_types=1);

namespace App\Command\User;

use Minicli\Command\CommandController;
use Minicli\Input;

class EditController extends CommandController
{

    public function usage(): void
    {
        $this->info("mandatory args:\n"
            . " user=login   : choose dolibarr login\n"
            . "options:\n"
            . "  --lock    : lock account (disable)\n"
            . "  --unlock  : unlock account (enable)\n"
            . "  --force   : disable dolibarr password rules and force given set password");
    }

    public function handle(): void
    {
        global $db, $conf;
        $force = false;
        $this->info('Change user from command line');

        if ($this->hasFlag('help')) {
            $this->usage();
        }
        if ($this->hasParam('login')) {
            $login = $this->getParam('login');
        }

        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";
        $u = new \User($db);
        $res = $u->fetch('', $login);
        if ($res) {
            $res2 = null;
            if ($this->hasFlag('lock')) {
                $res2 = $u->setstatus(\User::STATUS_DISABLED);
            }

            if ($this->hasFlag('unlock')) {
                $res2 = $u->setstatus(\User::STATUS_ENABLED);
            }

            if($res2 > 0) {
                $this->display("User updated, new status is " . $u->getLibStatut(0));
            } else {
                $this->error("User not updated, new status is always " . $u->getLibStatut(0));
            }
        } else {
            $this->error("Can't fetch user '$login' !");
        }
    }

    public function required(): array
    {
        return ['login'];
    }
}
