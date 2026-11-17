<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Dolibarr\CronKey;

final class CronkeyClearCommand extends Command
{
    public function name(): string
    {
        return 'system cronkey clear';
    }

    public function summary(): string
    {
        return 'Delete the scheduled jobs security key: jobs can no longer be triggered by URL';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function help(): string
    {
        return 'Dolibarr refuses every call of the HTTP trigger without a key, until a new key is set.';
    }

    public function execute(Context $ctx): array
    {
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Delete CRON_KEY']];
        }
        CronKey::clear();

        return ['cleared' => true];
    }

    public function renderText(array $data): string
    {
        return "CRON_KEY removed: scheduled jobs can no longer be triggered by URL until a new key is set.\n";
    }
}
