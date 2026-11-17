<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Dolibarr\CronKey;

final class CronkeyGenerateCommand extends Command
{
    public function name(): string
    {
        return 'system cronkey generate';
    }

    public function summary(): string
    {
        return 'Generate a random scheduled jobs security key (displayed once)';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function help(): string
    {
        return '32 hexadecimal characters (16 random bytes). The new key is displayed once: update the scheduler that calls the URL.';
    }

    public function execute(Context $ctx): array
    {
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Replace CRON_KEY with a random key']];
        }
        $value = bin2hex(random_bytes(16));
        CronKey::set($value, 'Set by dolicli system cronkey generate');

        return ['cron_key' => $value, 'url_path' => CronKey::urlPath()];
    }

    public function renderText(array $data): string
    {
        return "CRON_KEY updated. New value:\n{$data['cron_key']}\nURL: {$data['url_path']}?securitykey={$data['cron_key']}\n"
            . "Update the scheduler (crontab...) that calls this URL.\n";
    }
}
