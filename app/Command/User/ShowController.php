<?php

declare(strict_types=1);

namespace App\Command\User;

use Minicli\Command\CommandController;

class ShowController extends CommandController
{
    public function usage(): void
    {
        $this->info("Display detailed information about a Dolibarr user

Usage:
  ./dolibarr user show login=USERNAME

Parameters:
  login=USERNAME     - User login to display

Options:
  --help             - Display this help message

Examples:
  ./dolibarr user show login=admin
  ./dolibarr user show login=jdoe");
    }

    public function handle(): void
    {
        global $db, $conf;

        $this->info('Display Dolibarr user details');

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        // Check mandatory parameter
        if (!$this->hasParam('login')) {
            $this->error("Parameter 'login' is required!");
            $this->usage();
            return;
        }

        $login = $this->getParam('login');

        // Load user class
        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";
        $user = new \User($db);

        // Fetch the user
        $result = $user->fetch('', $login);

        if ($result <= 0) {
            $this->error("User '$login' not found!");
            $this->info("Please check the login and try again.");
            return;
        }

        // Display user information
        $this->rawOutput("\n═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  USER DETAILS\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");

        // Basic Information
        $this->rawOutput("BASIC INFORMATION:\n");
        $this->rawOutput("  ID:              " . $user->id . "\n");
        $this->rawOutput("  Login:           " . $user->login . "\n");
        $this->rawOutput("  First name:      " . ($user->firstname ?: '(not set)') . "\n");
        $this->rawOutput("  Last name:       " . ($user->lastname ?: '(not set)') . "\n");
        $this->rawOutput("  Full name:       " . $user->getFullName($user) . "\n");

        // Contact Information
        $this->rawOutput("\nCONTACT INFORMATION:\n");
        $this->rawOutput("  Email:           " . ($user->email ?: '(not set)') . "\n");
        $this->rawOutput("  Phone (office):  " . ($user->office_phone ?: '(not set)') . "\n");
        $this->rawOutput("  Phone (mobile):  " . ($user->user_mobile ?: '(not set)') . "\n");
        $this->rawOutput("  Fax:             " . ($user->office_fax ?: '(not set)') . "\n");

        // Status & Permissions
        $this->rawOutput("\nSTATUS & PERMISSIONS:\n");
        $statusLabel = $user->getLibStatut(5);
        $statusText = strip_tags($statusLabel);
        $statusDisplay = ($user->statut == 1) ? "  Status:          " . $statusText . "\n" : "  Status:          " . $statusText . " ✗\n";
        $this->rawOutput($statusDisplay);

        $adminLabel = ($user->admin == 1) ? 'Yes (Administrator)' : 'No';
        $this->rawOutput("  Admin rights:    " . $adminLabel . "\n");
        $this->rawOutput("  Super admin:     " . (($user->admin == 1) ? 'Yes' : 'No') . "\n");
        $this->rawOutput("  Entity:          " . $user->entity . "\n");

        // Authentication
        $this->rawOutput("\nAUTHENTICATION:\n");
        $hasApiKey = !empty($user->api_key);
        if ($hasApiKey) {
            $this->rawOutput("  API Token:       Set (xxxxx)\n");
        } else {
            $this->rawOutput("  API Token:       Not set\n");
        }

        $authMode = $user->auth_mode ?: 'dolibarr';
        $this->rawOutput("  Auth mode:       " . $authMode . "\n");

        // Account Dates
        $this->rawOutput("\nACCOUNT DATES:\n");
        if ($user->datec) {
            $this->rawOutput("  Created:         " . dol_print_date($user->datec, 'dayhour') . "\n");
        }
        if ($user->datem) {
            $this->rawOutput("  Modified:        " . dol_print_date($user->datem, 'dayhour') . "\n");
        }
        if ($user->datelastlogin) {
            $this->rawOutput("  Last login:      " . dol_print_date($user->datelastlogin, 'dayhour') . "\n");
        } else {
            $this->rawOutput("  Last login:      (never)\n");
        }
        if ($user->datepreviouslogin) {
            $this->rawOutput("  Previous login:  " . dol_print_date($user->datepreviouslogin, 'dayhour') . "\n");
        }

        // Additional Information
        $this->rawOutput("\nADDITIONAL INFORMATION:\n");
        $this->rawOutput("  Job:             " . ($user->job ?: '(not set)') . "\n");
        $this->rawOutput("  Signature:       " . ($user->signature ? 'Set' : '(not set)') . "\n");
        $this->rawOutput("  Note (public):   " . ($user->note_public ? 'Set' : '(not set)') . "\n");
        $this->rawOutput("  Note (private):  " . ($user->note_private ? 'Set' : '(not set)') . "\n");

        // Address
        if (!empty($user->address) || !empty($user->zip) || !empty($user->town) || !empty($user->state_code) || !empty($user->country_code)) {
            $this->rawOutput("\nADDRESS:\n");
            if ($user->address) {
                $this->rawOutput("  Address:         " . $user->address . "\n");
            }
            $location = trim(($user->zip ?: '') . ' ' . ($user->town ?: ''));
            if ($location) {
                $this->rawOutput("  City:            " . $location . "\n");
            }
            if ($user->state_code) {
                $this->rawOutput("  State:           " . $user->state_code . "\n");
            }
            if ($user->country_code) {
                $this->rawOutput("  Country:         " . $user->country_code . "\n");
            }
        }

        // Rights & Groups
        $this->rawOutput("\nRIGHTS & GROUPS:\n");

        // Get user's rights
        $user->getrights();
        $rightsCount = 0;
        if (isset($user->rights) && is_object($user->rights)) {
            $rightsCount = $this->countRights($user->rights);
        }
        $this->rawOutput("  Permissions:     " . $rightsCount . " permission(s) set\n");

        // Get user's groups
        $user->fetchAll('', '', 0, 0, array('customsql' => "ug.fk_user = " . $user->id));
        if (!empty($user->users_group_list)) {
            $this->rawOutput("  Groups:          " . count($user->users_group_list) . " group(s)\n");
            foreach ($user->users_group_list as $group) {
                $this->rawOutput("                   - " . $group['name'] . "\n");
            }
        } else {
            $this->rawOutput("  Groups:          No groups\n");
        }

        $this->rawOutput("\n═══════════════════════════════════════════════════════════\n");
    }

    /**
     * Count permissions recursively
     *
     * @param object $rights Rights object
     * @return int Number of permissions
     */
    private function countRights($rights): int
    {
        $count = 0;
        if (is_object($rights)) {
            foreach ($rights as $key => $value) {
                if (is_object($value)) {
                    $count += $this->countRights($value);
                } elseif ($value == 1) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public function required(): array
    {
        return [];
    }
}
