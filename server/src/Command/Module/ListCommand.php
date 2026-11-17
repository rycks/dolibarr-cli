<?php

namespace Dolicli\Command\Module;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Cli\TextRenderer;
use Dolicli\Dolibarr\Modules;

final class ListCommand extends Command
{
    public function name(): string
    {
        return 'module list';
    }

    public function summary(): string
    {
        return 'List installed modules and their state';
    }

    public function params(): array
    {
        return [
            Param::flag('active', 'Only active modules'),
            Param::flag('inactive', 'Only inactive modules'),
            Param::flag('external', 'Only external modules (custom/)'),
        ];
    }

    public function execute(Context $ctx): array
    {
        $modules = Modules::all();
        if ([] === $modules) {
            throw Failure::operation('NO_MODULE', 'No module found in the module directories.');
        }
        $rows = [];
        foreach ($modules as $modName => $module) {
            $active = Modules::isActive($module);
            if (($ctx->flag('active') && !$active) || ($ctx->flag('inactive') && $active)) {
                continue;
            }
            if ($ctx->flag('external') && !Modules::isExternal($module)) {
                continue;
            }
            $rows[] = Modules::describe($modName, $module);
        }

        return ['modules' => $rows, 'count' => count($rows)];
    }

    public function renderText(array $data): string
    {
        return TextRenderer::records($data['modules']);
    }
}
