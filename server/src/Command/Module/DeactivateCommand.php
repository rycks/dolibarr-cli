<?php

namespace Dolicli\Command\Module;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Dolibarr\Modules;

final class DeactivateCommand extends Command
{
    public function name(): string
    {
        return 'module deactivate';
    }

    public function summary(): string
    {
        return 'Deactivate a module (or all of them)';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::value('name', 'MODULE', 'Module class or name (modFacture, Facture); modules that depend on it are deactivated too'),
            Param::flag('all', 'Deactivate every active module except modUser and modSysLog'),
        ];
    }

    public function execute(Context $ctx): array
    {
        $name = (string) $ctx->value('name');
        if ($ctx->flag('all') === ('' !== $name)) {
            throw Failure::usage('MISSING_PARAMETER', 'Give name=MODULE or --all.');
        }
        Dolibarr::actingUser($ctx);

        if ($ctx->flag('all')) {
            return $this->deactivateAll($ctx);
        }

        $modName = Modules::resolve($name);
        if ('' === $modName) {
            throw Failure::operation('MODULE_NOT_FOUND', "Module '{$name}' not found: see dolicli module list.");
        }
        $modules = Modules::all();
        if (!Modules::isActive($modules[$modName])) {
            return ['module' => $modName, 'changed' => false, 'message' => "Module '{$modName}' is already inactive."];
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ["Deactivate {$modName} and the modules depending on it"]];
        }
        $error = Modules::deactivate($modName);
        if ('' !== $error) {
            throw Failure::operation('MODULE_DEACTIVATION_FAILED', "Failed to deactivate '{$modName}': {$error}");
        }

        return ['module' => $modName, 'changed' => true, 'message' => "Module '{$modName}' deactivated."];
    }

    /**
     * @return array<string, mixed>
     */
    private function deactivateAll(Context $ctx): array
    {
        $active = [];
        foreach (Modules::all() as $modName => $module) {
            if (Modules::isActive($module) && !in_array($modName, Modules::PROTECTED_MODULES, true)) {
                $active[] = $modName;
            }
        }
        if ([] === $active) {
            return ['deactivated' => [], 'failed' => [], 'message' => 'No active module to deactivate.'];
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => array_map(function ($modName) {
                return 'Deactivate ' . $modName;
            }, $active)];
        }
        $ctx->progress(count($active) . ' module(s) will be deactivated: ' . implode(', ', $active));
        if (!$ctx->confirm('Deactivate them all?')) {
            throw Failure::operation('CANCELLED', 'Cancelled, nothing deactivated.');
        }

        $done = [];
        $failed = [];
        foreach ($active as $modName) {
            $error = Modules::deactivate($modName);
            if ('' === $error) {
                $ctx->progress('Deactivated: ' . $modName);
                $done[] = $modName;
            } else {
                $failed[] = ['module' => $modName, 'error' => $error];
            }
        }
        if ([] !== $failed) {
            $ctx->addError('MODULE_DEACTIVATION_FAILED', count($failed) . ' module(s) could not be deactivated.');
        }

        return ['deactivated' => $done, 'failed' => $failed, 'message' => 'Deactivated: ' . count($done) . ', failed: ' . count($failed)];
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
