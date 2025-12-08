<?php

declare(strict_types=1);

namespace App\Command\Repair;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        $cmd = implode(" ", $this->getArgs());

        $this->info("Dolibarr database repair and maintenance commands

Usage:
 - $cmd run --help          - Display repair command help
 - $cmd run action=ACTION   - Execute a specific repair action

Available actions:
 - standard                 - Run SQL repair scripts + sync extrafields
 - force_disable_modules    - Force disable modules not found
 - restore_logos            - Restore third party logos
 - restore_pictures         - Restore user pictures
 - rebuild_thumbs           - Rebuild product thumbnails
 - clean_linked_elements    - Clean linked elements table
 - clean_menus              - Clean menus table
 - clean_orphan_dirs        - Clean orphan directories
 - clean_stock_batch        - Clean product stock batch table
 - clean_permissions        - Clean permissions table
 - set_time_spent           - Set empty time spent amounts
 - force_utf8               - Force UTF8 on tables (MySQL/MariaDB)
 - force_utf8mb4            - Force UTF8MB4 on tables (MySQL/MariaDB)
 - rebuild_sequences        - Rebuild sequences (PostgreSQL)
 - repair_dispatch_links    - Repair dispatch/supplier order links

Example:
 - $cmd run action=clean_menus mode=confirmed");
    }
}
