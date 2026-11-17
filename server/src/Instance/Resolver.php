<?php

namespace Dolicli\Instance;

use Dolicli\Cli\Failure;
use Dolicli\System\Log;

/**
 * Choice of the instance: --instance=<id> (installed mode), then --dolpath=<htdocs>,
 * then the DOLPATH environment variable, then ./htdocs.
 */
final class Resolver
{
    /**
     * @param string|null $instanceId Value of --instance
     * @param string|null $dolpath Value of --dolpath
     */
    public static function resolve($instanceId, $dolpath): Instance
    {
        if (null !== $instanceId && null !== $dolpath) {
            throw Failure::usage('INSTANCE_AMBIGUOUS', 'Give either --instance or --dolpath, not both.');
        }

        if (null !== $instanceId) {
            if (!Instance::isValidId($instanceId)) {
                throw Failure::usage('INSTANCE_INVALID', "Invalid instance identifier '{$instanceId}': expected [a-z0-9][a-z0-9_-]*, not 'all'.");
            }
            $ini = InstancesIni::load();
            if (!$ini->exists()) {
                Log::error('instance ' . $instanceId . ': ' . $ini->path() . ' not found');
                throw Failure::environment('INSTANCES_INI_MISSING', "{$ini->path()} not found: --instance needs the installed mode (dolicli setup install).");
            }
            $instance = $ini->get($instanceId);
            if (null === $instance) {
                throw Failure::usage('INSTANCE_UNKNOWN', "Instance '{$instanceId}' is not declared in {$ini->path()}.");
            }

            return self::checked($instance);
        }

        if (null !== $dolpath) {
            return self::checked(self::fromPath($dolpath, 'option'));
        }
        $env = getenv('DOLPATH');
        if (is_string($env) && '' !== $env) {
            return self::checked(self::fromPath($env, 'environment'));
        }

        return self::checked(self::fromPath('./htdocs', 'default'));
    }

    /**
     * Same as resolve(), null instead of a failure when no Dolibarr is found.
     *
     * @param string|null $instanceId
     * @param string|null $dolpath
     * @return Instance|null
     */
    public static function tryResolve($instanceId, $dolpath)
    {
        try {
            return self::resolve($instanceId, $dolpath);
        } catch (Failure $failure) {
            return null;
        }
    }

    private static function fromPath(string $path, string $source): Instance
    {
        $real = realpath($path);
        $htdocs = false === $real ? rtrim($path, '/') : $real;

        // Same htdocs declared in instances.ini: take its settings (run_as, php...).
        $ini = null;
        try {
            $ini = InstancesIni::load();
        } catch (Failure $failure) {
            Log::warning('instances.ini ignored: ' . $failure->getMessage());
        }
        if (null !== $ini) {
            foreach ($ini->all() as $instance) {
                if (realpath($instance->htdocs()) === $htdocs) {
                    return new Instance($instance->id(), $htdocs, $source, $instance->settings());
                }
            }
        }

        return new Instance(Instance::idFromHtdocs($htdocs), $htdocs, $source);
    }

    private static function checked(Instance $instance): Instance
    {
        $master = $instance->htdocs() . '/master.inc.php';
        if (!is_file($master) || !is_readable($master)) {
            Log::error('Dolibarr not found: ' . $master . ' is missing or unreadable');
            throw Failure::environment(
                'DOLIBARR_NOT_FOUND',
                "Dolibarr not found in '{$instance->htdocs()}' (no readable master.inc.php): "
                . 'use --instance, --dolpath, the DOLPATH environment variable, or run from the directory containing htdocs/.'
            );
        }

        return $instance;
    }
}
