<?php

namespace Dolicli\Command\Upgrade;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;
use Dolicli\Cli\Param;
use Dolicli\Upgrade\Workspace;

/**
 * Store the upgrade plan computed by the pilot on the server, so another administrator can resume it.
 */
final class PlanSaveCommand extends Command
{
    public function name(): string
    {
        return 'upgrade plan save';
    }

    public function summary(): string
    {
        return 'Store an upgrade plan (JSON) in documents/dolicli/upgrade/plan.json';
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
            Param::value('plan', 'JSON', 'Plan as a JSON object (stream of the ephemeral mode)'),
            Param::flag('stdin', 'Read the plan on standard input'),
        ];
    }

    public function execute(Context $ctx): array
    {
        $json = (string) $ctx->value('plan');
        if ('' === $json && $ctx->flag('stdin')) {
            $json = (string) stream_get_contents(STDIN);
        }
        $plan = Json::decode($json);
        if (!is_array($plan) || !isset($plan['steps']) || !is_array($plan['steps'])) {
            throw Failure::usage('INVALID_VALUE', 'The plan must be a JSON object with a steps list (plan= or --stdin).');
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Write ' . Workspace::current()->planFile()]];
        }
        $workspace = Workspace::current();
        $previous = $workspace->plan();
        if (isset($previous['progress']) && !isset($plan['progress'])) {
            $plan['progress'] = $previous['progress'];
        }
        $workspace->savePlan($plan);

        return ['plan_file' => $workspace->planFile(), 'steps' => count($plan['steps'])];
    }
}
