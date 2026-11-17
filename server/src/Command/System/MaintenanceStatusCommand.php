<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Dolibarr\Maintenance;

/**
 * Dolibarr maintenance mode (MAIN_ONLY_LOGIN_ALLOWED): status.
 */
final class MaintenanceStatusCommand extends Command
{
    public function name(): string
    {
        return 'system maintenance status';
    }

    public function summary(): string
    {
        return 'Show whether the maintenance mode is on, and for which login';
    }

    public function execute(Context $ctx): array
    {
        $login = Maintenance::currentLogin();

        return ['enabled' => '' !== $login, 'allowed_login' => '' === $login ? null : $login];
    }

    public function renderText(array $data): string
    {
        return $data['enabled']
            ? "Maintenance mode: ENABLED\nOnly user '{$data['allowed_login']}' can log in.\n"
            : "Maintenance mode: DISABLED\nAll users can log in.\n";
    }
}
