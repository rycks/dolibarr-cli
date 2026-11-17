<?php

namespace Dolicli\Dolibarr;

use Dolicli\Cli\ExitCode;
use Dolicli\Cli\Failure;
use Dolicli\System\Log;
use DolibarrModules;

/**
 * Find and load the Dolibarr module descriptors (core/modules/modXxx.class.php)
 * of every module directory, core and custom.
 */
final class Modules
{
    /** Modules never deactivated by "deactivate --all". */
    const PROTECTED_MODULES = ['modUser', 'modSysLog'];

    /** @var array<string, DolibarrModules>|null */
    private static $cache;

    /** @var bool */
    private static $tolerant = false;

    /** @var array<string, array{class: string, file: string, reason: string}> Descriptors to leave out, by path */
    private static $skipped = [];

    /** @var array<int, array{class: string, file: string, reason: string}> */
    private static $excluded = [];

    /**
     * @return array<string, DolibarrModules> Module class name => descriptor, sorted by name
     */
    public static function all(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }
        $db = Dolibarr::db();
        Dolibarr::requireCore('core/lib/admin.lib.php');
        Dolibarr::requireCore('core/lib/functions2.lib.php');
        Dolibarr::requireCore('core/modules/DolibarrModules.class.php');

        self::$excluded = [];
        $modules = [];
        foreach (dolGetModulesDirs() as $dir) {
            $entries = @scandir($dir);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (1 !== preg_match('/^(mod[A-Za-z0-9_]+)\.class\.php$/', $entry, $m)) {
                    continue;
                }
                $file = $dir . $entry;
                $modName = $m[1];
                if (isset($modules[$modName]) || !is_readable($file)) {
                    continue;
                }
                $key = self::pathKey($file);
                if (isset(self::$skipped[$key]) && !Descriptors::isCore($file)) {
                    self::$excluded[] = ['class' => $modName, 'file' => $file, 'reason' => self::$skipped[$key]['reason']];
                    continue;
                }
                $module = self::load($file, $modName, $db);
                if (null !== $module) {
                    $modules[$modName] = $module;
                }
            }
        }
        ksort($modules);
        self::$cache = $modules;

        return $modules;
    }

    /**
     * For module list: leave out the given descriptors (dolicli runs again without those that
     * stopped PHP) and the external descriptors that throw while loading, instead of failing.
     * Core descriptors are never left out.
     *
     * @param array<int, array{class: string, file: string, reason: string}> $skip
     */
    public static function tolerateFailures(array $skip)
    {
        self::$tolerant = true;
        self::$skipped = [];
        foreach ($skip as $entry) {
            self::$skipped[self::pathKey($entry['file'])] = $entry;
        }
        self::forget();
    }

    /**
     * Descriptors left out by the last call to all(), in the order they were met.
     *
     * @return array<int, array{class: string, file: string, reason: string}>
     */
    public static function excluded(): array
    {
        return self::$excluded;
    }

    public static function forget()
    {
        self::$cache = null;
    }

    /**
     * Include and build one descriptor, watched by Descriptors: if it stops PHP, the shutdown
     * handler names it. An exception becomes a FATAL_ERROR naming the file, or a left out
     * descriptor in the tolerant mode of module list.
     *
     * @param mixed $db
     * @return DolibarrModules|null
     */
    private static function load(string $file, string $modName, $db)
    {
        Descriptors::begin($file, $modName);
        try {
            // Including a second file declaring an already loaded class is a fatal error.
            if (!class_exists($modName, false)) {
                include_once $file;
            }
            $module = class_exists($modName, false) ? new $modName($db) : null;
        } catch (\Throwable $e) {
            $failed = Descriptors::interrupted();
            $reason = 'threw ' . get_class($e) . ': ' . $e->getMessage();
            Log::error("modules: {$file} {$reason}");
            if (self::$tolerant && !Descriptors::isCore($file)) {
                self::$excluded[] = ['class' => $modName, 'file' => $file, 'reason' => $reason];

                return null;
            }
            throw new Failure(
                'FATAL_ERROR',
                "Fatal error while loading {$file} (module {$modName}): uncaught " . get_class($e) . ': ' . $e->getMessage() . '.',
                ExitCode::ENVIRONMENT,
                ['file' => $file, 'module' => $modName, 'output' => null === $failed ? [] : $failed['output']]
            );
        }
        Descriptors::end();
        if (null === $module) {
            Log::warning("modules: {$file} does not declare class {$modName}");

            return null;
        }

        return $module instanceof DolibarrModules ? $module : null;
    }

    private static function pathKey(string $file): string
    {
        $real = realpath($file);

        return false === $real ? $file : $real;
    }

    /**
     * Module class name for a user input (modFacture, Facture, facture), empty string if not found.
     */
    public static function resolve(string $name): string
    {
        $name = trim($name);
        if (1 !== preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return '';
        }
        $wanted = [strtolower($name), 'mod' . strtolower($name)];
        foreach (array_keys(self::all()) as $modName) {
            if (in_array(strtolower($modName), $wanted, true)) {
                return $modName;
            }
        }

        return '';
    }

    public static function isActive(DolibarrModules $module): bool
    {
        return '' !== Dolibarr::conf((string) $module->const_name);
    }

    /**
     * Experimental or development modules (not meant for production).
     */
    public static function isUnstable(DolibarrModules $module): bool
    {
        return in_array((string) $module->version, ['experimental', 'development'], true);
    }

    public static function isExternal(DolibarrModules $module): bool
    {
        return 'external' === $module->isCoreOrExternalModule();
    }

    /**
     * Activate a module and its dependencies. Returns the error messages, empty on success.
     *
     * @return array<int, string>
     */
    public static function activate(string $modName): array
    {
        $result = activateModule($modName);
        if (is_array($result)) {
            $errors = isset($result['errors']) ? (array) $result['errors'] : [];
        } else {
            $errors = '' === (string) $result ? [] : [(string) $result];
        }
        $clean = [];
        foreach ($errors as $error) {
            $text = trim(strip_tags((string) $error));
            if ('' !== $text) {
                $clean[] = $text;
            }
        }
        self::forget();

        return $clean;
    }

    /**
     * Deactivate a module (and the modules depending on it). Returns an error message, empty on success.
     */
    public static function deactivate(string $modName): string
    {
        $error = trim(strip_tags((string) unActivateModule($modName)));
        self::forget();

        return $error;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(string $modName, DolibarrModules $module): array
    {
        $properties = get_object_vars($module);

        return [
            'class' => $modName,
            'number' => (int) $module->numero,
            'name' => (string) $module->getName(),
            'version' => (string) $module->getVersion(1),
            'active' => self::isActive($module),
            'external' => self::isExternal($module),
            'hidden' => !empty($module->hidden),
            'publisher' => html_entity_decode(strip_tags((string) $module->getPublisher()), ENT_QUOTES),
            // Optional properties of the descriptors, read without assuming they are declared.
            'need_dolibarr_version' => self::versionOf(isset($properties['need_dolibarr_version']) ? $properties['need_dolibarr_version'] : null),
            'phpmin' => self::versionOf(isset($properties['phpmin']) ? $properties['phpmin'] : null),
        ];
    }

    /**
     * Version declared by a descriptor as an array (array(16, 0)) or a string, empty when none.
     *
     * @param mixed $value
     */
    private static function versionOf($value): string
    {
        if (is_array($value)) {
            return implode('.', array_map('strval', $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
