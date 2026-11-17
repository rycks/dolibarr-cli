<?php

declare(strict_types=1);

namespace App\Command\Module;

use App\Support\Command;
use App\Util\ModuleScanner;

class DeactivateController extends Command
{
    /** Modules that are never deactivated by --all. */
    private const PROTECTED_MODULES = ['modUser', 'modSysLog'];

    public function summary(): string
    {
        return 'Deactivate a module (or all of them)';
    }

    public function usage(): void
    {
        $this->info('Deactivate a Dolibarr module

Usage:
  ./dolibarr module deactivate name=MODULE
  ./dolibarr module deactivate --all [--yes]

Parameters:
  name=MODULE - Module class or name (modFacture, Facture); modules that depend on it
                are deactivated too

Options:
  --all  - Deactivate every active module except modUser and modSysLog
  --yes  - With --all, do not ask for confirmation
  --help - Display this help message');
    }

    public function handle(): void
    {
        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! $this->requireDolibarr() || null === $this->actingUser()) {
            return;
        }

        if ($this->hasFlag('all')) {
            $this->deactivateAll();

            return;
        }

        $name = $this->param('name');
        if (null === $name || '' === $name) {
            $this->usage();
            $this->fail('Give name=MODULE or --all.');

            return;
        }

        $modName = ModuleScanner::resolve($name);
        if (null === $modName) {
            $this->fail("Module '{$name}' not found: see ./dolibarr module list.");

            return;
        }

        if ( ! ModuleScanner::isActive(ModuleScanner::all()[$modName])) {
            $this->info("Module '{$modName}' is already inactive.");

            return;
        }

        $error = $this->deactivate($modName);
        if ('' !== $error) {
            $this->fail("Failed to deactivate '{$modName}': {$error}");

            return;
        }

        $this->success("Module '{$modName}' deactivated.");
    }

    private function deactivate(string $modName): string
    {
        // unActivateModule() returns '' on success, an error message otherwise.
        return strip_tags((string) unActivateModule($modName));
    }

    private function deactivateAll(): void
    {
        $active = [];
        foreach (ModuleScanner::all() as $modName => $module) {
            if (ModuleScanner::isActive($module) && ! in_array($modName, self::PROTECTED_MODULES, true)) {
                $active[$modName] = $module;
            }
        }

        if ([] === $active) {
            $this->info('No active module to deactivate.');

            return;
        }

        $this->error('WARNING: '.count($active).' module(s) will be deactivated: '.implode(', ', array_keys($active)));
        if ( ! $this->confirm('Deactivate them all?')) {
            $this->info('Cancelled.');

            return;
        }

        $done = 0;
        $failed = 0;
        foreach ($active as $modName => $module) {
            $error = $this->deactivate($modName);
            if ('' === $error) {
                $this->display('Deactivated: '.$module->getName());
                $done++;
            } else {
                $this->error('Failed: '.$module->getName()." ({$error})");
                $failed++;
            }
        }

        $this->info("\nDeactivated: {$done}, failed: {$failed}");
        if ($failed > 0) {
            $this->fail("{$failed} module(s) could not be deactivated.");
        }
    }
}
