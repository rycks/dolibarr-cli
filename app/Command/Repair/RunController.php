<?php

declare(strict_types=1);

namespace App\Command\Repair;

use App\Support\Command;
use App\Util\InstallLock;
use App\Util\InstallScriptRunner;
use App\Util\Maintenance;

class RunController extends Command
{
    /**
     * dolicli action => [install/repair.php option, description].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ACTIONS = [
        'standard' => ['standard', 'Run SQL repair scripts + sync extrafields'],
        'force_disable_modules' => ['force_disable_of_modules_not_found', 'Force disable modules not found'],
        'restore_logos' => ['restore_thirdparties_logos', 'Restore third party logos'],
        'restore_pictures' => ['restore_user_pictures', 'Restore user pictures'],
        'rebuild_thumbs' => ['rebuild_product_thumbs', 'Rebuild product thumbnails'],
        'clean_linked_elements' => ['clean_linked_elements', 'Clean linked elements table'],
        'clean_menus' => ['clean_menus', 'Clean menus table'],
        'clean_orphan_dirs' => ['clean_orphelin_dir', 'Clean orphan directories'],
        'clean_stock_batch' => ['clean_product_stock_batch', 'Clean product stock batch table'],
        'clean_stock_negative_batch' => ['clean_product_stock_negative_if_batch', 'Clean negative stock of products with batch'],
        'clean_permissions' => ['clean_perm_table', 'Clean permissions table'],
        'set_time_spent' => ['set_empty_time_spent_amount', 'Set empty time spent amounts'],
        'force_utf8' => ['force_utf8_on_tables', 'Force UTF8 on tables (MySQL/MariaDB)'],
        'force_utf8mb4' => ['force_utf8mb4_on_tables', 'Force UTF8MB4 on tables (MySQL/MariaDB)'],
        'rebuild_sequences' => ['rebuild_sequences', 'Rebuild sequences (PostgreSQL)'],
        'repair_dispatch_links' => ['repair_link_dispatch_lines_supplier_order_lines', 'Repair dispatch/supplier order links'],
        'repair_supplier_order_ref' => ['repair_supplier_order_duplicate_ref', 'Repair duplicate supplier order references'],
    ];

    private ?InstallLock $installLock = null;

    private ?Maintenance $maintenance = null;

    public static function actionsHelp(string $indent): string
    {
        $lines = [];
        foreach (self::ACTIONS as $action => [, $description]) {
            $lines[] = $indent.str_pad($action, 27).'- '.$description;
        }

        return implode("\n", $lines);
    }

    public function summary(): string
    {
        return 'Run a database repair action (install/repair.php)';
    }

    public function usage(): void
    {
        $this->info("Execute Dolibarr database repair and maintenance functions (install/repair.php)

Usage:
  ./dolibarr repair run action=ACTION [mode=test|confirmed]

Parameters:
  action=ACTION      - Repair action to execute (required)
  mode=test          - Test mode (default): shows what would be done
  mode=confirmed     - Confirmed mode: actually executes the repair

Available actions:
".self::actionsHelp('  ').'

Examples:
  ./dolibarr repair run action=clean_menus mode=test
  ./dolibarr repair run action=clean_menus mode=confirmed
  ./dolibarr repair run action=standard mode=confirmed');
    }

    public function handle(): void
    {
        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        $action = $this->param('action');
        if (null === $action) {
            $this->usage();
            $this->fail("Parameter 'action' is required.");

            return;
        }

        $mode = $this->param('mode') ?? 'test';
        if ( ! in_array($mode, ['test', 'confirmed'], true)) {
            $this->fail("Invalid mode '{$mode}': must be 'test' or 'confirmed'.");

            return;
        }

        if ( ! isset(self::ACTIONS[$action])) {
            $this->usage();
            $this->fail("Unknown action '{$action}'.");

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        $option = self::ACTIONS[$action][0];
        $this->info("Executing repair action: {$action} ({$option}={$mode})\n");

        $this->maintenance = new Maintenance();
        $login = $this->maintenance->enable('Temporary maintenance for repair');
        if (null === $login) {
            $this->fail($this->maintenance->lastError());

            return;
        }
        if ($this->maintenance->wasEnabledByUs()) {
            $this->info("Maintenance mode enabled (only {$login} can log in)");
        }

        // repair.php refuses to run when install.lock exists, unless it is allowed through upgrade.unlock.
        $this->installLock = InstallLock::forCurrentInstall();
        $this->installLock->open();
        register_shutdown_function([$this, 'cleanup']);

        $runner = new InstallScriptRunner(fn (string $text) => $this->rawOutput($text));
        try {
            // The 'cli' argument makes repair.php exit with code 1 when it fails.
            $code = $runner->run(
                DOL_DOCUMENT_ROOT.'/install',
                'repair.php',
                ['cli'],
                ['action' => 'repair', $option => $mode],
                ['ALLOWED_IF_UPGRADE_UNLOCK_FOUND']
            );
        } finally {
            $this->cleanup();
        }

        $this->rawOutput("\n");
        if (0 !== $code) {
            $stderr = trim($runner->getStderr());
            if ('' !== $stderr) {
                $this->error("[repair.php stderr]\n".$stderr);
            }
            $this->fail("Repair action '{$action}' failed (repair.php exit code {$code}).");

            return;
        }

        $this->success("Repair action '{$action}' completed (mode {$mode}).");
    }

    /**
     * Lock the install pages again and leave maintenance mode. Runs at the end and at shutdown.
     */
    public function cleanup(): void
    {
        $this->installLock?->close();
        $this->installLock = null;

        if (null !== $this->maintenance && $this->maintenance->wasEnabledByUs()) {
            $this->info('Disabling maintenance mode...');
            if ( ! $this->maintenance->disable()) {
                $this->fail('Cannot disable maintenance mode: run ./dolibarr system maintenance disable');
            }
        }
    }
}
