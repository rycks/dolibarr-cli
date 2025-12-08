<?php

declare(strict_types=1);

namespace App\Command\Module;

use Minicli\Command\CommandController;
use App\Util\DolibarrHelper;

class ActivateController extends CommandController
{
    public function usage(): void
    {
        $this->info("Activate a Dolibarr module

Usage:
  ./dolibarr module activate name=MODULE_NAME
  ./dolibarr module activate --all

Parameters:
  name=MODULE_NAME - Name or constant of the module to activate
                     (e.g., modFacture, modSociete, modUser)

Options:
  --all  - Activate all available modules
  --help - Display this help message

Examples:
  ./dolibarr module activate name=modFacture
  ./dolibarr module activate name=modSociete
  ./dolibarr module activate --all");
    }

    public function handle(): void
    {
        global $db, $conf, $user;

        $this->info('Activate a Dolibarr module');

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        // Load required Dolibarr libraries
        require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

        // Create a user object if not exists (required for activateModule)
        if (!isset($user) || !is_object($user)) {
            $user = DolibarrHelper::getFirstSuperAdminUser();
            if (!$user) {
                $this->error("No super admin user found! Cannot activate modules.");
                $this->info("Please ensure at least one active super admin exists.");
                return;
            }
        }

        // Check if --all flag is set
        if ($this->hasFlag('all')) {
            $this->activateAllModules();
            return;
        }

        // Check if module name is provided
        if (!$this->hasParam('name')) {
            $this->error("Module name is required!");
            $this->info("Usage: ./dolibarr module activate name=MODULE_NAME");
            $this->info("   or: ./dolibarr module activate --all");
            $this->info("Use --help for more information");
            return;
        }

        $moduleName = $this->getParam('name');

        // Use Dolibarr's native activateModule function
        $result = activateModule($moduleName);

        if ($result > 0) {
            $this->success("Module '$moduleName' successfully activated!");
            $this->display("Module is now enabled in Dolibarr configuration.");
        } elseif ($result == 0) {
            $this->info("Module '$moduleName' may already be activated.");
        } else {
            $this->error("Failed to activate module '$moduleName'");
            $this->info("Please check the module name and try again.");
            $this->info("Use './dolibarr module list' to see available modules.");
        }
    }

    private function activateAllModules(): void
    {
        global $db;

        $this->info("Activating all available modules...\n");

        $modulesdir = dolGetModulesDirs();
        $activated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($modulesdir as $dir) {
            $handle = @opendir($dir);
            if (is_resource($handle)) {
                while (($file = readdir($handle)) !== false) {
                    if (is_readable($dir . $file) && substr($file, 0, 3) == 'mod' && substr($file, -10) == '.class.php') {
                        $modName = substr($file, 0, strlen($file) - 10);

                        // Skip certain system/internal modules
                        if (in_array($modName, ['modUser', 'modSysLog'])) {
                            continue;
                        }

                        include_once $dir . $file;
                        if (class_exists($modName)) {
                            $objMod = new $modName($db);

                            // Skip hidden modules
                            if (isset($objMod->hidden) && $objMod->hidden) {
                                $skipped++;
                                continue;
                            }

                            $result = activateModule($modName);

                            if ($result > 0) {
                                $this->display("Activated: " . $objMod->getName());
                                $activated++;
                            } elseif ($result == 0) {
                                $this->info("- Already active: " . $objMod->getName());
                                $skipped++;
                            } else {
                                $this->error("✗ Failed: " . $objMod->getName());
                                $failed++;
                            }
                        }
                    }
                }
                closedir($handle);
            }
        }

        $this->info("\n=== Summary ===");
        $this->success("Activated: $activated modules");
        if ($skipped > 0) {
            $this->info("Skipped: $skipped modules (already active or hidden)");
        }
        if ($failed > 0) {
            $this->error("Failed: $failed modules");
        }
    }

    public function required(): array
    {
        return [];
    }
}
