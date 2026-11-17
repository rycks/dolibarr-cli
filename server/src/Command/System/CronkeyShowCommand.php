<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Text;
use Dolicli\Dolibarr\CronKey;

final class CronkeyShowCommand extends Command
{
    public function name(): string
    {
        return 'system cronkey show';
    }

    public function summary(): string
    {
        return 'Show the scheduled jobs security key (masked without --show-secrets)';
    }

    public function help(): string
    {
        return "CRON_KEY is the securitykey parameter of the URL that triggers the scheduled jobs over HTTP.\n"
            . 'The command line script scripts/cron/cron_run_jobs.php does not need it.';
    }

    public function execute(Context $ctx): array
    {
        $value = CronKey::get();

        return [
            'set' => '' !== $value,
            'cron_key' => '' === $value ? null : ($ctx->showSecrets() ? $value : Text::mask($value)),
            'masked' => '' !== $value && !$ctx->showSecrets(),
            'url_path' => CronKey::urlPath(),
        ];
    }

    public function renderText(array $data): string
    {
        if (!$data['set']) {
            return "CRON_KEY is not set: scheduled jobs cannot be triggered by URL.\n";
        }

        return $data['cron_key'] . "\n";
    }
}
