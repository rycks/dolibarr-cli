<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Dolibarr\Maintenance;

final class MaintenanceEnableCommand extends Command
{
    public function name(): string
    {
        return 'system maintenance enable';
    }

    public function summary(): string
    {
        return 'Enable the maintenance mode: only one login may connect';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [Param::value('login', 'LOGIN', 'User allowed to log in (default: maintenance_login of the instance, else the first super admin)')];
    }

    public function help(): string
    {
        return "Dolibarr checks it on every request: open sessions of other users are blocked too.\n"
            . 'The mode stays on until dolicli system maintenance disable. Check that you can log in with the allowed user.';
    }

    public function execute(Context $ctx): array
    {
        $current = Maintenance::currentLogin();
        if ('' !== $current) {
            return ['enabled' => true, 'allowed_login' => $current, 'changed' => false];
        }
        $login = (string) $ctx->value('login');
        if ('' === $login) {
            $login = $ctx->instance()->setting('maintenance_login');
        }
        if ('' !== $login) {
            // Failure when the user does not exist: nobody could log in.
            Dolibarr::user($login);
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Enable the maintenance mode for ' . ('' !== $login ? "'{$login}'" : 'the first super admin')]];
        }
        $maintenance = new Maintenance();
        $allowed = $maintenance->enable('Set by dolicli system maintenance enable', $login);
        if ('' === $allowed) {
            throw Failure::operation('MAINTENANCE_FAILED', $maintenance->lastError());
        }

        return ['enabled' => true, 'allowed_login' => $allowed, 'changed' => true];
    }

    public function renderText(array $data): string
    {
        if (!$data['changed']) {
            return "Maintenance mode is already enabled for '{$data['allowed_login']}'.\n";
        }

        return "Maintenance mode ENABLED: only user '{$data['allowed_login']}' can log in.\nRestore normal access with: dolicli system maintenance disable\n";
    }
}
