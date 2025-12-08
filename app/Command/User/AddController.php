<?php

declare(strict_types=1);

namespace App\Command\User;

use Minicli\Command\CommandController;
use Minicli\Input;

class AddController extends CommandController
{
    public function usage(): void
    {
        $this->info("Add a new Dolibarr user

Usage:
  ./dolibarr user add login=USERNAME firstname=FIRST lastname=LAST [password=PASS] [email=EMAIL]

Mandatory parameters:
  login=USERNAME     - User login (unique)
  firstname=FIRST    - User first name
  lastname=LAST      - User last name

Optional parameters:
  password=PASS      - User password (if not set, will be asked interactively)
  email=EMAIL        - User email address
  admin=0|1          - Set user as admin (1) or not (0), default: 0

Options:
  --help             - Display this help message

Examples:
  ./dolibarr user add login=jdoe firstname=John lastname=Doe
  ./dolibarr user add login=jdoe firstname=John lastname=Doe password=mypass123
  ./dolibarr user add login=admin2 firstname=Super lastname=Admin admin=1 email=admin@example.com");
    }

    public function handle(): void
    {
        global $db, $conf, $user;

        $this->info('Add a new Dolibarr user');

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        // Check mandatory parameters
        if (!$this->hasParam('login')) {
            $this->error("Parameter 'login' is required!");
            $this->usage();
            return;
        }

        if (!$this->hasParam('firstname')) {
            $this->error("Parameter 'firstname' is required!");
            $this->usage();
            return;
        }

        if (!$this->hasParam('lastname')) {
            $this->error("Parameter 'lastname' is required!");
            $this->usage();
            return;
        }

        // Get parameters
        $login = $this->getParam('login');
        $firstname = $this->getParam('firstname');
        $lastname = $this->getParam('lastname');
        $email = $this->getParam('email') ?? '';
        $admin = $this->getParam('admin') ?? '0';

        // Get or ask for password
        if ($this->hasParam('password')) {
            $password = $this->getParam('password');
        } else {
            $password = "";
            while (trim($password) == "") {
                $input = new Input("Please enter the password for user '$login': ");
                $password = $input->read();
            }
        }

        $this->display("Creating user '$login' ($firstname $lastname)...");

        // Create a user object if not exists (required for user creation)
        if (!isset($user) || !is_object($user)) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
            $user = new \User($db);
            $user->fetch('', 'admin');
        }

        // Create new user object
        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";
        $newuser = new \User($db);

        // Check if user already exists
        $result = $newuser->fetch('', $login);
        if ($result > 0) {
            $this->error("User with login '$login' already exists!");
            $this->info("Please choose a different login.");
            return;
        }

        // Set user properties
        $newuser->login = $login;
        $newuser->firstname = $firstname;
        $newuser->lastname = $lastname;
        $newuser->email = $email;
        $newuser->admin = (int)$admin;
        $newuser->entity = $conf->entity;

        // Create the user
        $result = $newuser->create($user);

        if ($result > 0) {
            $this->success("User '$login' created successfully!");
            $this->display("User ID: " . $newuser->id);

            // Set password
            $newuser->setPassword($user, $password);
            $this->display("Password set for user '$login'");

            if ($newuser->admin == 1) {
                $this->info("User has administrator rights.");
            }

            if (!empty($email)) {
                $this->display("Email: $email");
            }
        } else {
            $this->error("Failed to create user '$login'");
            if ($newuser->error) {
                $this->error("Error: " . $newuser->error);
            }
            if (is_array($newuser->errors) && count($newuser->errors) > 0) {
                foreach ($newuser->errors as $error) {
                    $this->error("- $error");
                }
            }
        }
    }

    public function required(): array
    {
        return [];
    }
}
