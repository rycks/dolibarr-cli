<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Upgrade\Deployer;
use Dolicli\Upgrade\Workspace;

final class SwitchFilesCommand extends Command
{
    public function name(): string
    {
        return 'upgrade switch-files';
    }

    public function summary(): string
    {
        return 'Put the prepared files in place: htdocs -> htdocs.old-<date>, htdocs.new-<version> -> htdocs';
    }

    public function category(): string
    {
        return self::DESTRUCTIVE;
    }

    /**
     * Works on the file trees only: it must run even when the new files are broken.
     */
    public function needsDolibarr(): bool
    {
        return false;
    }

    public function needsInstance(): bool
    {
        return true;
    }

    public function params(): array
    {
        return [Param::value('version', 'X.Y.Z', 'Version prepared by upgrade deploy')->required()->matching('/^\d+\.\d+\.\d+$/')];
    }

    public function help(): string
    {
        return "The site runs the new files immediately: enable the maintenance mode first. The database still\n"
            . 'needs dolicli upgrade migrate. Undo with dolicli upgrade restore-files.';
    }

    public function execute(Context $ctx): array
    {
        $htdocs = $ctx->instance()->htdocs();
        $deployer = new Deployer($htdocs, $ctx->dataRoot());
        $version = (string) $ctx->value('version');
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => [
                'Rename ' . $htdocs . ' to ' . $htdocs . '.old-<date>',
                'Rename ' . $deployer->stagedDir($version) . ' to ' . $htdocs,
            ]];
        }
        $result = $deployer->switchTo($version);
        (new Workspace($ctx->dataRoot()))->recordStep('switch-files', 'done', $result['previous']);

        return $result;
    }

    public function renderText(array $data): string
    {
        return "New files in place in {$data['htdocs']}; previous files kept in {$data['previous']}.\nNext: dolicli upgrade migrate\n";
    }
}
