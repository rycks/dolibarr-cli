<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Upgrade\Workspace;

/**
 * Lease of the upgrade plan: keeps another pilot or administrator out between two steps.
 */
final class LeaseTakeCommand extends Command
{
    public function name(): string
    {
        return 'upgrade lease take';
    }

    public function summary(): string
    {
        return 'Take or renew the lease of the upgrade plan (exit code 5 when someone else holds it)';
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
        return [
            Param::value('holder', 'ID', 'Identifier of the holder (operator and pilot run)')->required()->matching('/^[A-Za-z0-9@._:-]{1,100}$/'),
            Param::value('ttl', 'SECONDS', 'Duration of the lease (default 3600)')->integer(),
        ];
    }

    public function execute(Context $ctx): array
    {
        $ttl = (int) ($ctx->value('ttl') ?: 3600);
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ["Take the plan lease for {$ttl} s"]];
        }

        return ['lease' => Workspace::current()->takeLease((string) $ctx->value('holder'), max(60, $ttl))];
    }
}
