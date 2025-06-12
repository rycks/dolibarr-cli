<?php

declare(strict_types=1);

namespace App\Command\User;

use Minicli\Command\CommandController;
use Minicli\Input;

class ListController extends CommandController
{

    public function usage(): void
    {
        $this->info("");
    }

    public function handle(): void
    {
        global $db, $conf;
        $force = false;
        $this->info('Get list of dolibarr user');

        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";
        $users = new \User($db);
        $res = $users->fetchAll();
        if ($res) {
            $content = [];
            $content[] = ['login', 'lastname', 'firstname', 'email'];
            foreach ($users->users as $u) {
                $content[] = [
                    $u->login,
                    $u->lastname,
                    $u->firstname,
                    $u->email
                ];
            }
            // print_r($content);
            $this->printTable($content);
        } else {
            $this->error("Can't fetch list !");
        }
    }
}
