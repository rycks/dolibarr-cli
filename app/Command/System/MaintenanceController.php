<?php

declare(strict_types=1);

namespace App\Command\System;

use Minicli\Command\CommandController;

class MaintenanceController extends CommandController
{
    public function usage(): void
    {
        $this->info("Dolibarr Maintenance Mode Management

Usage:
  ./dolibarr system maintenance enable [login=username]
  ./dolibarr system maintenance disable
  ./dolibarr system maintenance status

Commands:
  enable  - Enable maintenance mode (restrict login to one user)
  disable - Disable maintenance mode (allow all users to login)
  status  - Show current maintenance mode status

Parameters:
  login=username - Specify which user can login during maintenance
                   (default: first super admin user found)

Examples:
  ./dolibarr system maintenance status
  ./dolibarr system maintenance enable
  ./dolibarr system maintenance enable login=myuser
  ./dolibarr system maintenance disable

WARNING: Enabling maintenance mode restricts login to a single user.
Ensure the specified user exists and you have access to that account.
Do NOT disconnect before disabling maintenance mode or you may be locked out!");
    }

    public function handle(): void
    {
        global $db, $conf;

        // Check for help flag
        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        // Validate Dolibarr environment
        if (!$this->validateDolibarrEnvironment()) {
            return;
        }

        // Load required Dolibarr libraries
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        // Get subcommand from arguments
        $args = $this->getArgs();
        $subcommand = $args[3] ?? null;

        // Route to appropriate handler
        match($subcommand) {
            'enable' => $this->handleEnable(),
            'disable' => $this->handleDisable(),
            'status' => $this->handleStatus(),
            default => $this->usage()
        };
    }

    private function validateDolibarrEnvironment(): bool
    {
        global $db;

        if (!isset($db) || !$db) {
            $this->error("Dolibarr database connection not available!");
            $this->info("Please ensure DOLPATH is set correctly and Dolibarr is accessible.");
            return false;
        }

        if (!defined('DOL_DOCUMENT_ROOT')) {
            $this->error("DOL_DOCUMENT_ROOT is not defined!");
            $this->info("Please ensure Dolibarr is properly initialized.");
            return false;
        }

        return true;
    }

    private function handleStatus(): void
    {
        global $conf;

        $this->info("Checking maintenance mode status...\n");

        if (isset($conf->global->MAIN_ONLY_LOGIN_ALLOWED) &&
            !empty($conf->global->MAIN_ONLY_LOGIN_ALLOWED)) {
            $allowedUser = $conf->global->MAIN_ONLY_LOGIN_ALLOWED;
            $this->error("Maintenance mode: ENABLED");
            $this->display("Only user '$allowedUser' can login");
        } else {
            $this->success("Maintenance mode: DISABLED");
            $this->display("All users can login normally");
        }
    }

    private function handleEnable(): void
    {
        global $db, $conf;

        $this->info("Enabling maintenance mode...\n");

        // Get login parameter or find first super admin
        $allowedLogin = $this->getParam('login');

        if (!$allowedLogin) {
            // Find first super admin user
            $allowedLogin = $this->findFirstSuperAdmin();
            if ($allowedLogin) {
                $this->info("No user specified, using first super admin: $allowedLogin");
            } else {
                $this->error("No super admin user found in database!");
                $this->info("Please specify a user with login=username parameter.");
                return;
            }
        }

        // Validate user exists
        if (!$this->validateUserExists($allowedLogin)) {
            $this->error("User '$allowedLogin' does not exist!");
            $this->info("Cannot enable maintenance mode with non-existent user.");
            $this->info("Please specify a valid user with login=username parameter.");
            return;
        }

        // Set the maintenance mode constant
        $result = dolibarr_set_const(
            $db,
            'MAIN_ONLY_LOGIN_ALLOWED',
            $allowedLogin,
            'chaine',
            0,
            'Set by dolicli system maintenance enable',
            $conf->entity
        );

        // Display result
        if ($result > 0) {
            $this->success("Maintenance mode ENABLED");
            $this->display("Only user '$allowedLogin' can now login to Dolibarr.");
            $this->error("\nWARNING: Do not disconnect before disabling maintenance mode!");
            $this->info("Use './dolibarr system maintenance disable' to restore normal access.");
        } else {
            $this->error("Failed to enable maintenance mode");
            $this->info("Please check database permissions and try again.");
        }
    }

    private function handleDisable(): void
    {
        global $db, $conf;

        $this->info("Disabling maintenance mode...\n");

        // Delete the maintenance mode constant
        $result = dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity);

        // Display result
        if ($result > 0) {
            $this->success("Maintenance mode DISABLED");
            $this->display("All users can now login to Dolibarr normally.");
        } else {
            $this->info("Maintenance mode was not active or already disabled.");
        }
    }

    private function validateUserExists(string $login): bool
    {
        global $db;

        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";

        $user = new \User($db);
        $result = $user->fetch('', $login);

        return ($result > 0);
    }

    private function findFirstSuperAdmin(): ?string
    {
        global $db;

        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";

        // Find first active super admin user (admin=1 and statut=1)
        $sql = "SELECT login FROM " . MAIN_DB_PREFIX . "user";
        $sql .= " WHERE admin = 1 AND statut = 1";
        $sql .= " ORDER BY rowid ASC";
        $sql .= " LIMIT 1";

        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            if ($obj) {
                return $obj->login;
            }
        }

        return null;
    }
}
