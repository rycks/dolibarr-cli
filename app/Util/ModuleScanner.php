<?php

declare(strict_types=1);

namespace App\Util;

use DolibarrModules;

/**
 * Find and load the Dolibarr module descriptors (core/modules/modXxx.class.php)
 * of every module directory, core and custom.
 */
final class ModuleScanner
{
    /**
     * @return array<string, DolibarrModules> Module class name => descriptor, sorted by name
     */
    public static function all(): array
    {
        global $db;

        DolibarrHelper::requireCore('core/lib/admin.lib.php');
        DolibarrHelper::requireCore('core/lib/functions2.lib.php');
        DolibarrHelper::requireCore('core/modules/DolibarrModules.class.php');

        $modules = [];
        foreach (dolGetModulesDirs() as $dir) {
            foreach ((array) glob($dir.'mod*.class.php') as $file) {
                $file = (string) $file;
                $modName = basename($file, '.class.php');
                if (isset($modules[$modName]) || ! is_readable($file)) {
                    continue;
                }
                // Including a second file declaring an already loaded class is a fatal error.
                if ( ! class_exists($modName, false)) {
                    include_once $file;
                }
                if ( ! class_exists($modName, false)) {
                    DolibarrHelper::log("modules: {$file} does not declare class {$modName}", LOG_WARNING);

                    continue;
                }
                $module = new $modName($db);
                if ($module instanceof DolibarrModules) {
                    $modules[$modName] = $module;
                }
            }
        }
        ksort($modules);

        return $modules;
    }

    /**
     * Module class name for a user input (modFacture, Facture, facture), null if not found.
     */
    public static function resolve(string $name): ?string
    {
        $name = trim($name);
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return null;
        }
        $wanted = [mb_strtolower($name), 'mod'.mb_strtolower($name)];

        foreach (array_keys(self::all()) as $modName) {
            if (in_array(mb_strtolower($modName), $wanted, true)) {
                return $modName;
            }
        }

        return null;
    }

    public static function isActive(DolibarrModules $module): bool
    {
        return '' !== DolibarrHelper::conf((string) $module->const_name);
    }

    /**
     * Experimental or development modules (not meant for production).
     */
    public static function isUnstable(DolibarrModules $module): bool
    {
        return in_array((string) $module->version, ['experimental', 'development'], true);
    }
}
