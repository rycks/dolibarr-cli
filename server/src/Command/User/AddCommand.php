<?php

namespace Dolicli\Command\User;

use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use User;

final class AddCommand extends UserCommand
{
    public function name(): string
    {
        return 'user add';
    }

    public function summary(): string
    {
        return 'Create a user';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::value('login', 'LOGIN', 'User login (unique)')->required(),
            Param::value('firstname', 'FIRST', 'First name')->required(),
            Param::value('lastname', 'LAST', 'Last name')->required(),
            Param::value('email', 'EMAIL', 'Email address'),
            Param::value('admin', '0|1', 'Administrator rights')->choices(['0', '1']),
            Param::value('password', 'PASS', 'Password')->sensitive(),
            Param::flag('password-stdin', 'Read the password from standard input (scripts)'),
        ];
    }

    public function help(): string
    {
        return "Without --password-stdin, the password is asked without echo. The user and its password\n"
            . 'are created together: a password rejected by the Dolibarr rules creates no user.';
    }

    public function examples(): array
    {
        return [
            'user add login=jdoe firstname=John lastname=Doe',
            'user add login=jdoe firstname=John lastname=Doe --password-stdin < password.txt',
        ];
    }

    public function execute(Context $ctx): array
    {
        global $conf;

        $login = trim((string) $ctx->value('login'));
        $firstname = (string) $ctx->value('firstname');
        $lastname = (string) $ctx->value('lastname');
        if ('' === $login) {
            throw Failure::usage('INVALID_VALUE', 'login must not be empty.');
        }

        Dolibarr::requireCore('user/class/user.class.php');
        $db = Dolibarr::db();
        $existing = new User($db);
        $exists = $existing->fetch(0, $login);
        if ($exists < 0) {
            throw Failure::operation('USER_FETCH_FAILED', "Cannot check whether user '{$login}' exists: " . $existing->error);
        }
        if ($exists > 0) {
            throw Failure::operation('USER_EXISTS', "User with login '{$login}' already exists.");
        }

        $admin = (int) ($ctx->value('admin') ?: '0');
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'login' => $login, 'admin' => 1 === $admin, 'actions' => ["Create user '{$login}' ({$firstname} {$lastname})", 'Set its password']];
        }

        $password = $ctx->secret('password', "Password for user '{$login}': ");
        $acting = Dolibarr::actingUser($ctx);

        $ctx->progress("Creating user '{$login}'...");
        $user = new User($db);
        $user->login = $login;
        $user->firstname = $firstname;
        $user->lastname = $lastname;
        $user->email = (string) $ctx->value('email');
        $user->admin = $admin;
        $user->entity = (int) $conf->entity;

        // Create the user and set its password together: no user without a valid password.
        $db->begin();
        if ($user->create($acting) <= 0) {
            $db->rollback();
            throw Failure::operation('USER_CREATE_FAILED', "Failed to create user '{$login}': " . self::errorsOf($user));
        }
        $result = $user->setPassword($acting, $password);
        if (!is_string($result)) {
            $db->rollback();
            throw Failure::operation('PASSWORD_REJECTED', "User '{$login}' not created: password rejected by the Dolibarr password rules (" . self::errorsOf($user) . ').');
        }
        $db->commit();

        return ['login' => $login, 'id' => (int) $user->id, 'admin' => 1 === $admin];
    }

    public function renderText(array $data): string
    {
        return "User '{$data['login']}' created (id {$data['id']})" . ($data['admin'] ? ', with administrator rights' : '') . ".\n";
    }
}
