<?php

namespace Dolicli\Command\Monitor;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Json;
use Dolicli\Cli\Param;
use Dolicli\Monitor\Collector;
use Dolicli\Monitor\State;
use Dolicli\Monitor\Thresholds;
use Dolicli\System\Files;
use Dolicli\System\Log;
use Dolicli\System\Paths;
use Dolicli\System\Posix;

/**
 * Run the checks and update the state file /var/lib/dolicli/<run_as>/<instance>.json.
 */
final class CollectCommand extends Command
{
    public function name(): string
    {
        return 'monitor collect';
    }

    public function summary(): string
    {
        return 'Run the fast (or --slow) checks and update the state file of the instance';
    }

    public function params(): array
    {
        return [
            Param::flag('slow', 'Run the slow checks (backup age, sizes, filecheck, modules...)'),
            Param::flag('stdout', 'Write the complete state on standard output instead of the state file'),
        ];
    }

    public function help(): string
    {
        return "Run by cron under run_as (dolicli setup install writes /etc/cron.d/dolicli). The state file is\n"
            . "updated check by check: fast and slow collections merge. When the database is unreachable, the file\n"
            . "is still written, database.reachable critical and the checks that need the database unknown. During\n"
            . 'an operation on the instance, the database is not queried. --stdout writes a state file, not an envelope.';
    }

    public function execute(Context $ctx): array
    {
        $frequency = $ctx->flag('slow') ? Collector::SLOW : Collector::FAST;
        $collector = $this->collector($ctx);
        $runLock = null;
        if (!$ctx->flag('stdout') && Collector::FAST === $frequency) {
            // A fast collection that finds the previous one still running stops without error.
            $runLock = $this->runLock($ctx);
            if (null === $runLock) {
                return ['skipped' => true, 'message' => 'Previous fast collection still running'];
            }
        }
        try {
            $operation = [] !== $collector->operation();
            $checks = $collector->collect($frequency, true, $operation);
            if ($ctx->flag('stdout')) {
                return ['document' => State::document($collector->instanceInfo(), $checks)];
            }
            $file = $this->stateFile($ctx);
            $document = State::merge($file, $collector->instanceInfo(), $checks);
        } finally {
            if (null !== $runLock) {
                flock($runLock, LOCK_UN);
                fclose($runLock);
            }
        }

        return ['state_file' => $file, 'frequency' => $frequency, 'status' => $document['status'], 'checks' => count($checks)];
    }

    public function rawResult(array $data)
    {
        return isset($data['document']) ? Json::encode($data['document']) . "\n" : null;
    }

    public function renderText(array $data): string
    {
        if (!empty($data['skipped'])) {
            return $data['message'] . "\n";
        }

        return "{$data['checks']} {$data['frequency']} checks written to {$data['state_file']} (status {$data['status']}).\n";
    }

    /**
     * Database unreachable: the state file is still written, with database.reachable critical.
     */
    public function dolibarrLoadFailed(Context $ctx, string $reason)
    {
        if ($ctx->flag('stdout')) {
            return;
        }
        $collector = $this->collector($ctx);
        $frequency = $ctx->flag('slow') ? Collector::SLOW : Collector::FAST;
        $checks = $collector->collect($frequency, false, false, $reason);
        if (Collector::SLOW === $frequency) {
            $checks += $collector->collect(Collector::FAST, false, false, $reason);
        }
        State::merge($this->stateFile($ctx), $collector->instanceInfo(), $checks);
    }

    private function collector(Context $ctx): Collector
    {
        $instance = $ctx->instance();

        return new Collector($instance, Thresholds::forInstance($instance->id()), $ctx->dataRoot());
    }

    private function stateFile(Context $ctx): string
    {
        $instance = $ctx->instance();
        $runAs = $instance->setting('run_as', Posix::currentUserName());

        return Paths::stateFile($runAs, $instance->id());
    }

    /**
     * @return resource|null Null when the previous fast collection still runs
     */
    private function runLock(Context $ctx)
    {
        $file = substr($this->stateFile($ctx), 0, -5) . '.fast.lock';
        Files::ensureDir(dirname($file), 02750);
        $handle = @fopen($file, 'c');
        if (false === $handle) {
            Log::error("monitor: cannot open {$file}");

            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            Log::warning('monitor: previous fast collection still running');

            return null;
        }

        return $handle;
    }
}
