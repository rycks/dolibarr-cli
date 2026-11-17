<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Support\Command;
use User;

class AddController extends Command
{
    public function usage(): void
    {
        $this->info('Add a new Dolibarr user

Usage:
  ./dolibarr user add login=USERNAME firstname=FIRST lastname=LAST [email=EMAIL] [admin=0|1]

Mandatory parameters:
  login=USERNAME     - User login (unique)
  firstname=FIRST    - User first name
  lastname=LAST      - User last name

Optional parameters:
  email=EMAIL        - User email address
  admin=0|1          - Set user as admin (1) or not (0), default: 0
  password=PASS      - User password. Avoid it: the password stays in the shell history
                       and is visible to other users in the process list (ps)

Options:
  --password-stdin   - Read the password from standard input (scripts)
  --help             - Display this help message

Without password= nor --password-stdin, the password is asked without echo.

Examples:
  ./dolibarr user add login=jdoe firstname=John lastname=Doe
  echo "$PASS" | ./dolibarr user add login=jdoe firstname=John lastname=Doe --password-stdin
  ./dolibarr user add login=admin2 firstname=Super lastname=Admin admin=1 email=admin@example.com');
    }

    public function handle(): void
    {
        global $db, $conf;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        foreach (['login', 'firstname', 'lastname'] as $required) {
            if (null === $this->param($required) || '' === trim((string) $this->param($required))) {
                $this->usage();
                $this->fail("Parameter '{$required}' is required.");

                return;
            }
        }

        $login = (string) $this->param('login');
        $admin = $this->param('admin') ?? '0';
        if ( ! in_array($admin, ['0', '1'], true)) {
            $this->fail("Invalid admin={$admin}: use 0 or 1.");

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        $actingUser = $this->actingUser();
        if (null === $actingUser) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
        $newuser = new User($db);
        $exists = $newuser->fetch(0, $login);
        if ($exists < 0) {
            $this->fail("Cannot check whether user '{$login}' exists: ".$newuser->error);

            return;
        }
        if ($exists > 0) {
            $this->fail("User with login '{$login}' already exists.");

            return;
        }

        $password = $this->param('password') ?? $this->askSecret("Password for user '{$login}': ");
        if (null === $password) {
            return;
        }

        $this->display("Creating user '{$login}' (".$this->param('firstname').' '.$this->param('lastname').')...');

        $newuser = new User($db);
        $newuser->login = $login;
        $newuser->firstname = (string) $this->param('firstname');
        $newuser->lastname = (string) $this->param('lastname');
        $newuser->email = (string) ($this->param('email') ?? '');
        $newuser->admin = (int) $admin;
        $newuser->entity = (int) $conf->entity;

        // Create the user and set its password together: no user without a valid password.
        $db->begin();
        $result = $newuser->create($actingUser);
        if ($result <= 0) {
            $db->rollback();
            $this->fail("Failed to create user '{$login}': ".$this->errorsOf($newuser));

            return;
        }

        $passResult = $newuser->setPassword($actingUser, $password);
        if ( ! is_string($passResult)) {
            $db->rollback();
            $this->fail("User '{$login}' not created: password rejected by Dolibarr password rules (".$this->errorsOf($newuser).').');

            return;
        }
        $db->commit();

        $this->success("User '{$login}' created (id ".$newuser->id.').');
        if (1 === (int) $newuser->admin) {
            $this->info('User has administrator rights.');
        }
    }

    private function errorsOf(User $user): string
    {
        $errors = array_filter(array_merge([(string) $user->error], (array) $user->errors));

        return [] === $errors ? 'unknown error' : strip_tags(implode(', ', array_unique($errors)));
    }
}
