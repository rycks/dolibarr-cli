<?php

declare(strict_types=1);

namespace App\Command\Repair;

use Minicli\Command\CommandController;
use App\Util\DolibarrHelper;

class RunController extends CommandController
{
    private array $actionMapping = [
        'standard' => 'standard',
        'force_disable_modules' => 'force_disable_module',
        'restore_logos' => 'restore_thirdparties_logos',
        'restore_pictures' => 'restore_user_pictures',
        'rebuild_thumbs' => 'rebuild_product_thumbs',
        'clean_linked_elements' => 'clean_linked_elements',
        'clean_menus' => 'clean_menus',
        'clean_orphan_dirs' => 'clean_orphelin_dir',
        'clean_stock_batch' => 'clean_product_stock_batch',
        'clean_permissions' => 'clean_perm_table',
        'set_time_spent' => 'set_empty_time_spent_amount',
        'force_utf8' => 'force_utf8_on_tables',
        'force_utf8mb4' => 'force_utf8mb4_on_tables',
        'rebuild_sequences' => 'rebuild_sequences',
        'repair_dispatch_links' => 'repair_link_dispatch_lines_supplier_order_lines'
    ];

    public function usage(): void
    {
        $this->info("Execute Dolibarr database repair and maintenance functions

Usage:
  ./dolibarr repair run action=ACTION [mode=test|confirmed]

Parameters:
  action=ACTION      - Repair action to execute (required)
  mode=test          - Test mode (default): shows what would be done
  mode=confirmed     - Confirmed mode: actually executes the repair

Available actions:
  standard                 - Run SQL repair scripts + sync extrafields
  force_disable_modules    - Force disable modules not found
  restore_logos            - Restore third party logos
  restore_pictures         - Restore user pictures
  rebuild_thumbs           - Rebuild product thumbnails
  clean_linked_elements    - Clean linked elements table
  clean_menus              - Clean menus table
  clean_orphan_dirs        - Clean orphan directories
  clean_stock_batch        - Clean product stock batch table
  clean_permissions        - Clean permissions table
  set_time_spent           - Set empty time spent amounts
  force_utf8               - Force UTF8 on tables (MySQL/MariaDB)
  force_utf8mb4            - Force UTF8MB4 on tables (MySQL/MariaDB)
  rebuild_sequences        - Rebuild sequences (PostgreSQL)
  repair_dispatch_links    - Repair dispatch/supplier order links

Examples:
  ./dolibarr repair run action=clean_menus mode=test
  ./dolibarr repair run action=clean_menus mode=confirmed
  ./dolibarr repair run action=standard mode=confirmed");
    }

    public function handle(): void
    {
        global $db, $conf, $user;

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        if (!$this->hasParam('action')) {
            $this->error("Parameter 'action' is required!");
            $this->usage();
            return;
        }

        $action = $this->getParam('action');
        $mode = $this->getParam('mode') ?? 'test';

        if (!in_array($mode, ['test', 'confirmed'])) {
            $this->error("Invalid mode '$mode'. Must be 'test' or 'confirmed'.");
            return;
        }

        if (!isset($this->actionMapping[$action])) {
            $this->error("Unknown action '$action'");
            $this->usage();
            return;
        }

        // Get the repair.php action name
        $repairAction = $this->actionMapping[$action];

        // Load required libraries
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $this->info("Executing repair action: $action");
        $this->info("Mode: $mode\n");

        // Check if we're already in maintenance mode
        $wasInMaintenanceMode = !empty($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
        $maintenanceUser = null;

        if (!$wasInMaintenanceMode) {
            // Find first super admin user
            $maintenanceUser = DolibarrHelper::findFirstSuperAdmin();
            if (!$maintenanceUser) {
                $this->error("No super admin user found! Cannot enable maintenance mode.");
                $this->info("Please ensure at least one active super admin exists.");
                return;
            }

            $this->info("Enabling temporary maintenance mode (user  $maintenanceUser)...");
            dolibarr_set_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $maintenanceUser, 'chaine', 0, 'Temporary maintenance for repair', $conf->entity);
            // Reload conf to get the new value
            $conf->global->MAIN_ONLY_LOGIN_ALLOWED = $maintenanceUser;
        }

        try {
            // Prepare GET parameters to simulate repair.php call
            $_GET['action'] = $repairAction;

            if ($repairAction === 'standard') {
                $_GET['standard'] = ($mode === 'confirmed') ? 'confirmed' : 'test';
            } else {
                $_GET[$repairAction] = ($mode === 'confirmed') ? 'confirmed' : 'test';
            }

            // Capture output from repair.php
            ob_start();

            // Include repair.php which will execute the repair
            $repairFile = DOL_DOCUMENT_ROOT . '/install/repair.php';

            if (!file_exists($repairFile)) {
                throw new \Exception("Repair file not found: $repairFile");
            }

            // Execute repair.php
            include $repairFile;

            // Get the output
            $output = ob_get_clean();

            // Parse and display the output (strip HTML tags for CLI)
            $cleanOutput = strip_tags($output);
            $cleanOutput = html_entity_decode($cleanOutput, ENT_QUOTES | ENT_HTML5);

            // Display the output
            $this->rawOutput($cleanOutput);

        } catch (\Exception $e) {
            ob_end_clean();
            $this->error("Error executing repair  " . $e->getMessage());
        } finally {
            // Clean up GET parameters
            unset($_GET['action']);
            unset($_GET['standard']);
            unset($_GET[$repairAction]);

            // Restore maintenance mode state
            if (!$wasInMaintenanceMode) {
                $this->info("\nDisabling temporary maintenance mode...");
                dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity);
                unset($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
            }
        }

        $this->rawOutput("\n");
        $this->success("Repair action completed");
    }

    public function required(): array
    {
        return [];
    }
}
