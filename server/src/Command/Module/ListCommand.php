<?php

namespace Dolicli\Command\Module;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Cli\TextRenderer;
use Dolicli\Dolibarr\Descriptors;
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

    public function help(): string
    {
        return "An external module whose descriptor stops PHP while it loads (exit, die, fatal error) or throws\n"
            . "is left out: the list comes without it, the module is named in excluded (class, file, reason)\n"
            . "with a warning MODULE_DESCRIPTOR_FAILED. dolicli runs again in a child process for each such\n"
            . 'descriptor, at most ' . Descriptors::MAX_SKIPPED . ' times. A core module is never left out: its failure stays an error.';
    }

    public function toleratesFailedDescriptors(): bool
    {
        return true;
    }

    public function execute(Context $ctx): array
    {
        Modules::tolerateFailures(Descriptors::skippedFromEnvironment());
        $modules = Modules::all();
        $excluded = Modules::excluded();
        foreach ($excluded as $entry) {
            $ctx->warn(
                'MODULE_DESCRIPTOR_FAILED',
                "Module {$entry['class']} left out of the list: its descriptor {$entry['file']} {$entry['reason']}.",
                ['class' => $entry['class'], 'file' => $entry['file']]
            );
        }
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

        return ['modules' => $rows, 'count' => count($rows), 'excluded' => $excluded];
    }

    public function renderText(array $data): string
    {
        return TextRenderer::records($data['modules']);
    }
}
