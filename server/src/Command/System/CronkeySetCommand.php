<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\CronKey;

final class CronkeySetCommand extends Command
{
    public function name(): string
    {
        return 'system cronkey set';
    }

    public function summary(): string
    {
        return 'Set the scheduled jobs security key to a given value';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::value('key', 'KEY', 'New value of CRON_KEY')->sensitive(),
            Param::flag('password-stdin', 'Read the key from standard input'),
        ];
    }

    public function help(): string
    {
        return 'Update the scheduler (crontab...) that calls the URL with the new key.';
    }

    public function execute(Context $ctx): array
    {
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Replace CRON_KEY']];
        }
        $value = trim($ctx->secret('key', 'New CRON_KEY: '));
        if ('' === $value) {
            throw Failure::usage('INVALID_VALUE', 'The key must not be empty.');
        }
        CronKey::set($value, 'Set by dolicli system cronkey set');

        return ['updated' => true, 'url_path' => CronKey::urlPath()];
    }

    public function renderText(array $data): string
    {
        return "CRON_KEY updated. Update the scheduler that calls {$data['url_path']}?securitykey=...\n";
    }
}
