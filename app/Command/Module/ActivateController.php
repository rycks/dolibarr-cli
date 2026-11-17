<?php

declare(strict_types=1);

namespace App\Command\Module;

use App\Support\Command;
use App\Util\ModuleScanner;

class ActivateController extends Command
{
    public function summary(): string
    {
        return 'Activate a module (or all of them)';
    }

    public function usage(): void
    {
        $this->info('Activate a Dolibarr module

Usage:
  ./dolibarr module activate name=MODULE
  ./dolibarr module activate --all [--include-unstable] [--yes]

Parameters:
  name=MODULE - Module class or name (modFacture, Facture), dependencies included

Options:
  --all               - Activate every available module (test environments only)
  --include-unstable  - With --all, also activate experimental and development modules
  --yes               - With --all, do not ask for confirmation
  --help              - Display this help message

Examples:
  ./dolibarr module activate name=modFacture
  ./dolibarr module activate name=Societe
  ./dolibarr module activate --all --yes');
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
            $this->activateAll();

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

        $module = ModuleScanner::all()[$modName];
        if (ModuleScanner::isActive($module)) {
            $this->info("Module '{$modName}' is already active.");

            return;
        }

        $errors = $this->activate($modName);
        if ([] !== $errors) {
            $this->fail("Failed to activate '{$modName}': ".implode('; ', $errors));

            return;
        }

        $this->success("Module '{$modName}' activated.");
    }

    /**
     * @return array<int, string> Error messages, empty on success
     */
    private function activate(string $modName): array
    {
        $result = activateModule($modName);
        $errors = is_array($result) ? (array) ($result['errors'] ?? []) : ['unexpected result'];

        return array_values(array_filter(array_map(static fn ($e): string => strip_tags((string) $e), $errors)));
    }

    private function activateAll(): void
    {
        $candidates = [];
        foreach (ModuleScanner::all() as $modName => $module) {
            if ( ! empty($module->hidden) || ModuleScanner::isActive($module)) {
                continue;
            }
            if (ModuleScanner::isUnstable($module) && ! $this->hasFlag('include-unstable')) {
                continue;
            }
            $candidates[$modName] = $module;
        }

        if ([] === $candidates) {
            $this->info('Every available module is already active.');

            return;
        }

        $this->info(count($candidates).' module(s) will be activated: '.implode(', ', array_keys($candidates)));
        if ( ! $this->confirm('Activate them all?')) {
            $this->info('Cancelled.');

            return;
        }

        $activated = 0;
        $failed = 0;
        foreach ($candidates as $modName => $module) {
            // A previous activation may have enabled it as a dependency.
            if (ModuleScanner::isActive($module)) {
                continue;
            }
            $errors = $this->activate($modName);
            if ([] === $errors) {
                $this->display('Activated: '.$module->getName());
                $activated++;
            } else {
                $this->error('Failed: '.$module->getName().' ('.implode('; ', $errors).')');
                $failed++;
            }
        }

        $this->info("\nActivated: {$activated}, failed: {$failed}");
        if ($failed > 0) {
            $this->fail("{$failed} module(s) could not be activated.");
        }
    }
}
