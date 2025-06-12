<?php

declare(strict_types=1);

namespace App\Command\Users;

use Minicli\Command\CommandController;
use Minicli\Input;

class PasswordController extends CommandController
{

    public function usage(): void
    {
        $this->info("mandatory args:\n"
            . " user=login   : choose dolibarr login\n"
            . " newpass=xxxx : the password, if not set script will ask it as interactive\n\n"
            . "options:\n"
            . "  --help  : display this help\n"
            . "  --force : disable dolibarr password rules and force given set password");
    }

    public function handle(): void
    {
        global $db, $conf;
        $force = false;
        $this->info('Change user password from command line');

        if ($this->hasFlag('help')) {
            $this->usage();
        }

        if ($this->hasFlag('force')) {
            $force = true;
            $this->usage();
        }

        if ($this->hasParam('login')) {
            $login = $this->getParam('login');
        }
        if ($this->hasParam('newpass')) {
            $newpass = $this->getParam('newpass');
        } else {
            $newpass = "";
            while (trim($newpass) == "") {
                $input = new Input("Please enter the new password : ");
                $newpass = $input->read();
            }
        }
        $this->display("We will try to change password for user '$login' with the new password '$newpass'");

        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";
        $u = new \User($db);
        $res = $u->fetch('', $login);
        if ($res) {
            if ($force) {
                $conf->global->USER_PASSWORD_GENERATED = 'none';
            }
            $res2 = $u->setPassword($u, $newpass);
            if ($res2 == $newpass) {
                $this->display("New password is set for '$login'");
            } else {
                $this->error("Can't change password for user '$login'");
                $this->info("Please have a look on dolibarr password setup (admin >> security >> passwords)!");
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
