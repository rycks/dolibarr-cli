<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Upgrade\Deployer;
use Dolicli\Upgrade\Workspace;

final class RestoreFilesCommand extends Command
{
    public function name(): string
    {
        return 'upgrade restore-files';
    }

    public function summary(): string
    {
        return 'Put the previous files back (latest htdocs.old-<date>); the current ones become htdocs.failed-<date>';
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

    public function help(): string
    {
        return "Only the files: a migrated database must be restored from its backup by the administrator,\n"
            . 'the old files do not work with a database migrated to a newer version.';
    }

    public function execute(Context $ctx): array
    {
        $htdocs = $ctx->instance()->htdocs();
        $deployer = new Deployer($htdocs, $ctx->dataRoot());
        if ($ctx->dryRun()) {
            $previous = $deployer->previousTrees();

            return ['dry_run' => true, 'actions' => [
                'Rename ' . $htdocs . ' to ' . $htdocs . '.failed-<date>',
                'Rename ' . ([] === $previous ? '(no htdocs.old-* found)' : end($previous)) . ' to ' . $htdocs,
            ]];
        }
        $result = $deployer->restore();
        (new Workspace($ctx->dataRoot()))->recordStep('restore-files', 'done', $result['restored_from']);

        return $result;
    }

    public function renderText(array $data): string
    {
        return "Files of {$data['restored_from']} back in {$data['htdocs']}; the newer ones are in {$data['set_aside']}.\n";
    }
}
