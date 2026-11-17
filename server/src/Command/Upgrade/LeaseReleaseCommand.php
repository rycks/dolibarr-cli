<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Upgrade\Workspace;

final class LeaseReleaseCommand extends Command
{
    public function name(): string
    {
        return 'upgrade lease release';
    }

    public function summary(): string
    {
        return 'Release the lease of the upgrade plan';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function allowedInBatch(): bool
    {
        return false;
    }

    public function params(): array
    {
        return [Param::value('holder', 'ID', 'Identifier given to upgrade lease take')->required()->matching('/^[A-Za-z0-9@._:-]{1,100}$/')];
    }

    public function execute(Context $ctx): array
    {
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Release the plan lease']];
        }
        Workspace::current()->releaseLease((string) $ctx->value('holder'));

        return ['released' => true];
    }
}
