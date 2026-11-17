<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Support\Command;
use User;

class PasswordController extends Command
{
    public function summary(): string
    {
        return 'Change a user password';
    }

    public function usage(): void
    {
        $this->info('Change the password of a Dolibarr user

Usage:
  ./dolibarr user password login=LOGIN [--force]

Parameters:
  login=LOGIN        - Dolibarr login (required)
  newpass=PASS       - New password. Avoid it: the password stays in the shell history
                       and is visible to other users in the process list (ps)

Options:
  --password-stdin   - Read the new password from standard input (scripts)
  --force            - Bypass the Dolibarr password rules (length, complexity)
  --help             - Display this help message

Without newpass= nor --password-stdin, the password is asked without echo.');
    }

    public function handle(): void
    {
        global $db, $conf;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        $login = $this->param('login');
        if (null === $login || '' === $login) {
            $this->usage();
            $this->fail("Parameter 'login' is required.");

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
        $u = new User($db);
        $res = $u->fetch(0, $login);
        if ($res <= 0) {
            $this->fail(0 === $res ? "User '{$login}' not found." : "Cannot fetch user '{$login}': ".$u->error);

            return;
        }

        $newpass = $this->param('newpass') ?? $this->askSecret("New password for '{$login}': ");
        if (null === $newpass) {
            return;
        }

        if ($this->hasFlag('force')) {
            // Without a password generator configured, setPassword() applies no rule.
            $conf->global->USER_PASSWORD_GENERATED = '';
        }

        $actingUser = $this->actingUser() ?? $u;
        $result = $u->setPassword($actingUser, $newpass);
        if ( ! is_string($result)) {
            $this->fail("Cannot change password for user '{$login}': ".strip_tags($u->error ?: 'unknown error'));
            $this->info('Check the password rules (Home > Setup > Security > Passwords) or use --force.');

            return;
        }

        $this->success("New password set for '{$login}'.");
    }
}
