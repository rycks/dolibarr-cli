<?php

namespace Dolicli\Command\Repair;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Maintenance;
use Dolicli\Upgrade\InstallLock;
use Dolicli\Upgrade\InstallScriptRunner;

final class RunCommand extends Command
{
    /**
     * dolicli action => [install/repair.php option, description].
     */
    const ACTIONS = [
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

    public function name(): string
    {
        return 'repair run';
    }

    public function summary(): string
    {
        return 'Run a database repair action (install/repair.php)';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::value('action', 'ACTION', 'Repair action to execute')->required()->choices(array_keys(self::ACTIONS)),
            Param::value('mode', 'MODE', 'test (default): shows what would be done; confirmed: actually executes the repair')->choices(['test', 'confirmed']),
        ];
    }

    public function help(): string
    {
        $lines = ["Available actions:"];
        foreach (self::ACTIONS as $action => $definition) {
            $lines[] = '  ' . str_pad($action, 28) . $definition[1];
        }
        $lines[] = '';
        $lines[] = 'Works on installations locked by install.lock: only the upgrade pages are opened for the duration';
        $lines[] = 'of the repair (upgrade.unlock), the maintenance mode is on meanwhile.';

        return implode("\n", $lines);
    }

    public function examples(): array
    {
        return ['repair run action=clean_menus mode=test', 'repair run action=clean_menus mode=confirmed'];
    }

    public function execute(Context $ctx): array
    {
        $action = (string) $ctx->value('action');
        $mode = (string) ($ctx->value('mode') ?: 'test');
        $option = self::ACTIONS[$action][0];

        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => [
                'Enable the maintenance mode',
                'Open the upgrade pages (upgrade.unlock)',
                "Run install/repair.php {$option}={$mode} in a separate PHP process",
                'Lock the install pages again and disable the maintenance mode',
            ]];
        }

        $ctx->progress("Executing repair action: {$action} ({$option}={$mode})");
        $maintenance = new Maintenance();
        $login = $maintenance->enable('Temporary maintenance for repair', $ctx->instance()->setting('maintenance_login'));
        if ('' === $login) {
            throw Failure::operation('MAINTENANCE_FAILED', $maintenance->lastError());
        }
        $ctx->defer(function () use ($maintenance, $ctx) {
            if ($maintenance->wasEnabledByUs() && !$maintenance->disable()) {
                $ctx->warn('MAINTENANCE_STILL_ON', 'Cannot disable the maintenance mode: run dolicli system maintenance disable.');
            }
        });

        // repair.php refuses to run when install.lock exists, unless it is allowed through upgrade.unlock.
        $installLock = InstallLock::forCurrentInstall();
        $installLock->open();
        $ctx->defer(function () use ($installLock) {
            $installLock->close();
        });

        $output = '';
        $runner = new InstallScriptRunner(function ($text) use (&$output, $ctx) {
            $output .= $text;
            $ctx->progress(rtrim($text, "\n"));
        });
        // The 'cli' argument makes repair.php exit with code 1 when it fails.
        $code = $runner->run(
            DOL_DOCUMENT_ROOT . '/install',
            'repair.php',
            ['cli'],
            ['action' => 'repair', $option => $mode],
            ['ALLOWED_IF_UPGRADE_UNLOCK_FOUND']
        );
        if (0 !== $code) {
            throw Failure::operation(
                'REPAIR_FAILED',
                "Repair action '{$action}' failed (repair.php exit code {$code})" . ('' !== trim($runner->getStderr()) ? ': ' . trim($runner->getStderr()) : '.')
            );
        }

        return ['action' => $action, 'mode' => $mode, 'output' => trim($output)];
    }

    public function renderText(array $data): string
    {
        return "Repair action '{$data['action']}' completed (mode {$data['mode']}).\n";
    }
}
