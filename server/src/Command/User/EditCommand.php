<?php

namespace Dolicli\Command\User;

use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;

final class EditCommand extends UserCommand
{
    public function name(): string
    {
        return 'user edit';
    }

    public function summary(): string
    {
        return 'Lock or unlock a user account';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            $this->loginParam(),
            Param::flag('lock', 'Disable the account'),
            Param::flag('unlock', 'Enable the account'),
        ];
    }

    public function help(): string
    {
        return 'Give exactly one of --lock, --unlock. To change a password: dolicli user password.';
    }

    public function execute(Context $ctx): array
    {
        $lock = $ctx->flag('lock');
        if ($lock === $ctx->flag('unlock')) {
            throw Failure::usage('MISSING_PARAMETER', 'Give exactly one of --lock, --unlock.');
        }
        $login = (string) $ctx->value('login');
        $user = $this->target($login);
        $status = $lock ? 'disabled' : 'enabled';

        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'login' => $login, 'status' => $status, 'actions' => ["Set user '{$login}' {$status}"]];
        }
        $user->oldcopy = clone $user;
        if ($user->setstatus($lock ? 0 : 1) < 0) {
            throw Failure::operation('USER_UPDATE_FAILED', "User '{$login}' not updated: " . self::errorsOf($user));
        }

        return ['login' => $login, 'status' => $status];
    }

    public function renderText(array $data): string
    {
        return "User '{$data['login']}' is now {$data['status']}.\n";
    }
}
