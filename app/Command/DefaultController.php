<?php

declare(strict_types=1);

namespace App\Command;

use Minicli\Command\CommandController;

class DefaultController extends CommandController
{
    public function handle(): void
    {
        global $conf;

        $this->rawOutput("\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  DOLICLI - Dolibarr Command Line Interface\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");

        // Display current Dolibarr version if available
        if (defined('DOL_VERSION')) {
            $this->info("Dolibarr version: ".DOL_VERSION);
            $this->rawOutput("\n");
        }

        $this->info("Available command categories:\n");

        $this->rawOutput("  SYSTEM\n");
        $this->rawOutput("   ./dolibarr system upgrade      - Upgrade Dolibarr (full or database only)\n");
        $this->rawOutput("\n");

        $this->rawOutput("  USER\n");
        $this->rawOutput("   ./dolibarr user add            - Create new users\n");
        $this->rawOutput("   ./dolibarr user show           - Display user details\n");
        $this->rawOutput("   ./dolibarr user token          - Generate API tokens\n");
        $this->rawOutput("   ./dolibarr user list           - List all users\n");
        $this->rawOutput("\n");

        $this->rawOutput("  MODULE\n");
        $this->rawOutput("   ./dolibarr module list         - List all modules\n");
        $this->rawOutput("   ./dolibarr module activate     - Activate modules\n");
        $this->rawOutput("   ./dolibarr module deactivate   - Deactivate modules\n");
        $this->rawOutput("\n");

        $this->rawOutput("  REPAIR\n");
        $this->rawOutput("   ./dolibarr repair run          - Run database maintenance\n");
        $this->rawOutput("\n");

        $this->rawOutput("  THIRDPARTY\n");
        $this->rawOutput("   ./dolibarr thirdparty list     - List third parties\n");
        $this->rawOutput("\n");

        $this->rawOutput("  BACKUP\n");
        $this->rawOutput("   ./dolibarr backup              - Backup operations\n");
        $this->rawOutput("\n");

        $this->rawOutput("  ANONYMIZE\n");
        $this->rawOutput("   ./dolibarr anonymize run       - Anonymize all personal data\n");
        $this->rawOutput("\n");

        $this->info("Usage:\n");
        $this->rawOutput("  ./dolibarr <category> <command> [options]\n");
        $this->rawOutput("  ./dolibarr <category> <command> --help  (for detailed help)\n");
        $this->rawOutput("\n");

        $this->info("Examples:\n");
        $this->rawOutput("  ./dolibarr system upgrade --check\n");
        $this->rawOutput("  ./dolibarr system upgrade --minor\n");
        $this->rawOutput("  ./dolibarr user add --help\n");
        $this->rawOutput("  ./dolibarr module list\n");
        $this->rawOutput("  ./dolibarr repair run action=clean_menus mode=confirmed\n");
        $this->rawOutput("\n");

        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->info("For detailed help on a specific category, run:");
        $this->rawOutput("  ./dolibarr <category>\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");
    }
}
