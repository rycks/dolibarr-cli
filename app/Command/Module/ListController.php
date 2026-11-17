<?php

declare(strict_types=1);

namespace App\Command\Module;

use App\Support\Command;
use App\Util\ModuleScanner;

class ListController extends Command
{
    public function usage(): void
    {
        $this->info('List installed Dolibarr modules

Usage:
  ./dolibarr module list [--active] [--inactive]

Options:
  --active     Only active modules
  --inactive   Only inactive modules

Columns: class, number, name, version, active, external, hidden, publisher.');
    }

    public function handle(): void
    {
        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        $modules = ModuleScanner::all();
        if ([] === $modules) {
            $this->fail('No module found in the module directories.');

            return;
        }

        $content = [['class', 'number', 'name', 'version', 'active', 'external', 'hidden', 'publisher']];
        foreach ($modules as $modName => $module) {
            $active = ModuleScanner::isActive($module);
            if (($this->hasFlag('active') && ! $active) || ($this->hasFlag('inactive') && $active)) {
                continue;
            }
            $content[] = [
                $modName,
                (string) $module->numero,
                (string) $module->getName(),
                (string) $module->getVersion(1),
                $active ? 'yes' : 'no',
                'external' === $module->isCoreOrExternalModule() ? 'yes' : 'no',
                ! empty($module->hidden) ? 'yes' : 'no',
                html_entity_decode(strip_tags((string) $module->getPublisher()), ENT_QUOTES),
            ];
        }

        $this->printTable($content);
    }
}
