<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Support\Command;
use App\Util\Maintenance;
use User;

class MaintenanceController extends Command
{
    public function usage(): void
    {
        $this->info('Dolibarr Maintenance Mode Management

Usage:
  ./dolibarr system maintenance enable [login=username]
  ./dolibarr system maintenance disable
  ./dolibarr system maintenance status

Commands:
  enable  - Enable maintenance mode (restrict login to one user)
  disable - Disable maintenance mode (allow all users to login)
  status  - Show current maintenance mode status

Parameters:
  login=username - User allowed to log in during maintenance
                   (default: first super admin user found)

Make sure the allowed user exists and that you can log in with it.
The maintenance mode stays on until ./dolibarr system maintenance disable.');
    }

    public function handle(): void
    {
        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        match ($this->action()) {
            'enable' => $this->handleEnable(),
            'disable' => $this->handleDisable(),
            'status' => $this->handleStatus(),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): void
    {
        $this->usage();
        $this->fail('Give an action: enable, disable or status.');
    }

    private function handleStatus(): void
    {
        $allowed = Maintenance::currentLogin();
        if ('' !== $allowed) {
            $this->error('Maintenance mode: ENABLED');
            $this->display("Only user '{$allowed}' can login");
        } else {
            $this->success('Maintenance mode: DISABLED');
            $this->display('All users can login normally');
        }
    }

    private function handleEnable(): void
    {
        global $db;

        $current = Maintenance::currentLogin();
        if ('' !== $current) {
            $this->info("Maintenance mode is already enabled for '{$current}'.");

            return;
        }

        $login = $this->param('login');
        if (null !== $login) {
            require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
            $u = new User($db);
            if ($u->fetch(0, $login) <= 0) {
                $this->fail("User '{$login}' does not exist: maintenance mode not enabled.");

                return;
            }
        }

        $maintenance = new Maintenance();
        $allowed = $maintenance->enable('Set by dolicli system maintenance enable', $login);
        if (null === $allowed) {
            $this->fail($maintenance->lastError());

            return;
        }

        $this->success('Maintenance mode ENABLED');
        $this->display("Only user '{$allowed}' can now login to Dolibarr.");
        $this->info("Use './dolibarr system maintenance disable' to restore normal access.");
    }

    private function handleDisable(): void
    {
        if ('' === Maintenance::currentLogin()) {
            $this->info('Maintenance mode was not active.');

            return;
        }

        if ( ! Maintenance::forceDisable()) {
            $this->fail('Failed to disable maintenance mode: check the database.');

            return;
        }

        $this->success('Maintenance mode DISABLED');
        $this->display('All users can now login to Dolibarr normally.');
    }
}
