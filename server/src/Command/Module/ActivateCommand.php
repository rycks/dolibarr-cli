<?php

namespace Dolicli\Command\Module;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Dolibarr\Modules;

final class ActivateCommand extends Command
{
    public function name(): string
    {
        return 'module activate';
    }

    public function summary(): string
    {
        return 'Activate a module (or all of them)';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::value('name', 'MODULE', 'Module class or name (modFacture, Facture), dependencies included'),
            Param::flag('all', 'Activate every available module (test environments only)'),
            Param::flag('include-unstable', 'With --all, also activate experimental and development modules'),
        ];
    }

    public function examples(): array
    {
        return ['module activate name=modFacture', 'module activate name=Societe', 'module activate --all --yes'];
    }

    public function execute(Context $ctx): array
    {
        $name = (string) $ctx->value('name');
        if ($ctx->flag('all') === ('' !== $name)) {
            throw Failure::usage('MISSING_PARAMETER', 'Give name=MODULE or --all.');
        }
        Dolibarr::actingUser($ctx);

        if ($ctx->flag('all')) {
            return $this->activateAll($ctx);
        }

        $modName = Modules::resolve($name);
        if ('' === $modName) {
            throw Failure::operation('MODULE_NOT_FOUND', "Module '{$name}' not found: see dolicli module list.");
        }
        $modules = Modules::all();
        if (Modules::isActive($modules[$modName])) {
            return ['module' => $modName, 'changed' => false, 'message' => "Module '{$modName}' is already active."];
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ["Activate {$modName} and the modules it depends on"]];
        }
        $errors = Modules::activate($modName);
        if ([] !== $errors) {
            throw Failure::operation('MODULE_ACTIVATION_FAILED', "Failed to activate '{$modName}': " . implode('; ', $errors));
        }

        return ['module' => $modName, 'changed' => true, 'message' => "Module '{$modName}' activated."];
    }

    /**
     * @return array<string, mixed>
     */
    private function activateAll(Context $ctx): array
    {
        $candidates = [];
        foreach (Modules::all() as $modName => $module) {
            if (!empty($module->hidden) || Modules::isActive($module)) {
                continue;
            }
            if (Modules::isUnstable($module) && !$ctx->flag('include-unstable')) {
                continue;
            }
            $candidates[$modName] = $module;
        }
        if ([] === $candidates) {
            return ['activated' => [], 'failed' => [], 'message' => 'Every available module is already active.'];
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => array_map(function ($modName) {
                return 'Activate ' . $modName;
            }, array_keys($candidates))];
        }
        $ctx->progress(count($candidates) . ' module(s) will be activated: ' . implode(', ', array_keys($candidates)));
        if (!$ctx->confirm('Activate them all?')) {
            throw Failure::operation('CANCELLED', 'Cancelled, nothing activated.');
        }

        $activated = [];
        $failed = [];
        foreach (array_keys($candidates) as $modName) {
            // A previous activation may have enabled it as a dependency.
            $all = Modules::all();
            if (isset($all[$modName]) && Modules::isActive($all[$modName])) {
                continue;
            }
            $errors = Modules::activate($modName);
            if ([] === $errors) {
                $ctx->progress('Activated: ' . $modName);
                $activated[] = $modName;
            } else {
                $failed[] = ['module' => $modName, 'error' => implode('; ', $errors)];
            }
        }
        if ([] !== $failed) {
            $ctx->addError('MODULE_ACTIVATION_FAILED', count($failed) . ' module(s) could not be activated.');
        }

        return ['activated' => $activated, 'failed' => $failed, 'message' => 'Activated: ' . count($activated) . ', failed: ' . count($failed)];
    }

    public function renderText(array $data): string
    {
        $out = $data['message'] . "\n";
        if (!empty($data['failed'])) {
            foreach ($data['failed'] as $failure) {
                $out .= '  failed: ' . $failure['module'] . ' (' . $failure['error'] . ")\n";
            }
        }

        return $out;
    }
}
