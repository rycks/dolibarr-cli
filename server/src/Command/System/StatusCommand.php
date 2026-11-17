<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Monitor\Collector;
use Dolicli\Monitor\Formats;
use Dolicli\Monitor\State;
use Dolicli\Monitor\Thresholds;

/**
 * Immediate summary: the fast checks, measured now, without touching the state file.
 */
final class StatusCommand extends Command
{
    public function name(): string
    {
        return 'system status';
    }

    public function summary(): string
    {
        return 'Immediate summary of the instance (fast checks, measured now)';
    }

    public function execute(Context $ctx): array
    {
        $instance = $ctx->instance();
        $collector = new Collector($instance, Thresholds::forInstance($instance->id()), $ctx->dataRoot());
        $checks = $collector->collect(Collector::FAST, true, [] !== $collector->operation());
        $document = State::document($collector->instanceInfo(), $checks);

        return ['status' => $document['status'], 'checks' => $document['checks']];
    }

    public function renderText(array $data): string
    {
        return Formats::text(['instance' => ['id' => 'current'], 'status' => $data['status'], 'generated_at' => date('c'), 'checks' => (array) $data['checks']]);
    }
}
