<?php

declare(strict_types=1);

namespace App\Command\Module;

use Minicli\Command\CommandController;

class DeactivateController extends CommandController
{
    public function usage(): void
    {
        $this->info("Deactivate a Dolibarr module

Usage:
  ./dolibarr module deactivate name=MODULE_NAME
  ./dolibarr module deactivate --all

Parameters:
  name=MODULE_NAME - Name or constant of the module to deactivate
                     (e.g., modFacture, modSociete, modUser)

Options:
  --all  - Deactivate all active modules (except critical ones)
  --help - Display this help message

Examples:
  ./dolibarr module deactivate name=modFacture
  ./dolibarr module deactivate name=modSociete
  ./dolibarr module deactivate --all");
    }

    public function handle(): void
    {
        global $db, $conf, $user;

        $this->info('Deactivate a Dolibarr module');

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        // Load required Dolibarr libraries
        require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';

        // Create a user object if not exists (required for unActivateModule)
        if (!isset($user) || !is_object($user)) {
            require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
            $user = new \User($db);
            //TODO admin a modifier
            $user->fetch('', 'admin');
        }

        // Check if --all flag is set
        if ($this->hasFlag('all')) {
            $this->deactivateAllModules();
            return;
        }

        // Check if module name is provided
        if (!$this->hasParam('name')) {
            $this->error("Module name is required!");
            $this->info("Usage: ./dolibarr module deactivate name=MODULE_NAME");
            $this->info("   or: ./dolibarr module deactivate --all");
            $this->info("Use --help for more information");
            return;
        }

        $moduleName = $this->getParam('name');

        // Use Dolibarr's native unActivateModule function
        $result = unActivateModule($moduleName);

        if ($result > 0) {
            $this->success("Module '$moduleName' successfully deactivated!");
            $this->display("Module is now disabled in Dolibarr configuration.");
        } elseif ($result == 0) {
            $this->info("Module '$moduleName' may already be deactivated.");
        } else {
            $this->error("Failed to deactivate module '$moduleName'");
            $this->info("Please check the module name and try again.");
            $this->info("Use './dolibarr module list' to see available modules.");
        }
    }

    private function deactivateAllModules(): void
    {
        global $db, $conf;

        $this->info("Deactivating all active modules...\n");
        $this->error("WARNING: This will deactivate all modules except critical ones!");

        $modulesdir = dolGetModulesDirs();
        $deactivated = 0;
        $failed = 0;
        $skipped = 0;

        // Critical modules that should never be deactivated
        $criticalModules = ['modUser', 'modSysLog'];

        foreach ($modulesdir as $dir) {
            $handle = @opendir($dir);
            if (is_resource($handle)) {
                while (($file = readdir($handle)) !== false) {
                    if (is_readable($dir . $file) && substr($file, 0, 3) == 'mod' && substr($file, -10) == '.class.php') {
                        $modName = substr($file, 0, strlen($file) - 10);

                        // Skip critical modules
                        if (in_array($modName, $criticalModules)) {
                            $this->info("! Protected: $modName (critical module, skipped)");
                            $skipped++;
                            continue;
                        }

                        include_once $dir . $file;
                        if (class_exists($modName)) {
                            $objMod = new $modName($db);

                            // Check if module is currently active
                            $const_name = 'MAIN_MODULE_' . strtoupper($modName);
                            if (!isset($conf->global->$const_name) || empty($conf->global->$const_name)) {
                                // Module not active, skip
                                $skipped++;
                                continue;
                            }

                            $result = unActivateModule($modName);

                            if ($result > 0) {
                                $this->display("Deactivated: " . $objMod->getName());
                                $deactivated++;
                            } elseif ($result == 0) {
                                $this->info("- Already inactive: " . $objMod->getName());
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
        $this->success("Deactivated: $deactivated modules");
        if ($skipped > 0) {
            $this->info("Skipped: $skipped modules (inactive, critical or protected)");
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
