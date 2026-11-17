<?php

namespace Dolicli\Command\User;

use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;

final class PasswordCommand extends UserCommand
{
    public function name(): string
    {
        return 'user password';
    }

    public function summary(): string
    {
        return 'Change a user password';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            $this->loginParam(),
            Param::value('newpass', 'PASS', 'New password')->sensitive(),
            Param::flag('password-stdin', 'Read the new password from standard input (scripts)'),
            Param::flag('force', 'Bypass the Dolibarr password rules (length, complexity)'),
        ];
    }

    public function help(): string
    {
        return 'Without --password-stdin, the password is asked without echo. It is never displayed.';
    }

    public function execute(Context $ctx): array
    {
        global $conf;

        $login = (string) $ctx->value('login');
        $user = $this->target($login);
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'login' => $login, 'actions' => ["Set a new password for '{$login}'" . ($ctx->flag('force') ? ' without the password rules' : '')]];
        }

        $password = $ctx->secret('newpass', "New password for '{$login}': ");
        $acting = Dolibarr::actingUser($ctx);
        if ($ctx->flag('force')) {
            // Without a password generator configured, setPassword() applies no rule.
            $conf->global->USER_PASSWORD_GENERATED = '';
        }
        $result = $user->setPassword($acting, $password);
        if (!is_string($result)) {
            throw Failure::operation(
                'PASSWORD_REJECTED',
                "Cannot change the password of '{$login}': " . self::errorsOf($user) . '. Check the password rules (Setup > Security > Passwords) or use --force.'
            );
        }

        return ['login' => $login, 'changed' => true];
    }

    public function renderText(array $data): string
    {
        return "New password set for '{$data['login']}'.\n";
    }
}
