<?php

namespace Dolicli\Command\Monitor;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;
use Dolicli\Cli\Param;
use Dolicli\Instance\Instance;
use Dolicli\Instance\InstancesIni;
use Dolicli\Instance\Resolver;
use Dolicli\Monitor\Formats;
use Dolicli\Monitor\State;
use Dolicli\Monitor\Thresholds;
use Dolicli\System\Files;
use Dolicli\System\Paths;
use Dolicli\System\Posix;

/**
 * Read the state file and render it. Runs under the monitoring account: it reads neither
 * conf.php nor the database.
 */
final class ShowCommand extends Command
{
    public function name(): string
    {
        return 'monitor show';
    }

    public function summary(): string
    {
        return 'Render the state file of an instance (json, prometheus, nagios, text)';
    }

    public function needsDolibarr(): bool
    {
        return false;
    }

    public function needsInstance(): bool
    {
        return false;
    }

    public function allowedAsRoot(): bool
    {
        return true;
    }

    public function extraFormats(): array
    {
        return ['prometheus', 'nagios'];
    }

    public function params(): array
    {
        return [
            Param::value('file', 'PATH', 'State file to read (default: the one of --instance)'),
            Param::value('check', 'ID', 'With --format=nagios, only this check'),
            Param::value('output', 'PATH', 'Write the result to this file atomically (.prom file of the textfile collector)'),
        ];
    }

    public function help(): string
    {
        return "Freshness is applied at read time: a fast check older than three times the fast interval, or a\n"
            . "slow one older than 26 h, is unknown; collector.age is computed. With --format=nagios, any error\n"
            . 'of dolicli (missing or unreadable file) gives UNKNOWN (3), never CRITICAL.';
    }

    public function examples(): array
    {
        return [
            'monitor show --instance=client-a-prod --format=nagios',
            'monitor show --instance=client-a-prod --format=prometheus --output=/var/lib/node_exporter/textfile/dolibarr-client-a-prod.prom',
        ];
    }

    public function execute(Context $ctx): array
    {
        $file = (string) $ctx->value('file');
        $instanceId = '';
        if ('' === $file) {
            list($file, $instanceId) = $this->locate($ctx);
        }
        $document = State::read($file);
        if ([] === $document) {
            throw Failure::operation('STATE_NOT_FOUND', "No readable state file {$file}: has dolicli monitor collect run (cron of dolicli setup install)?");
        }
        if ('' === $instanceId && isset($document['instance']['id'])) {
            $instanceId = (string) $document['instance']['id'];
        }
        $interval = (int) Thresholds::forInstance($instanceId)->option('fast_interval', (string) State::FAST_INTERVAL);
        $document = State::withFreshness($document, time(), max(60, $interval));

        $output = (string) $ctx->value('output');
        if ('' !== $output) {
            $rendered = $this->render($ctx->format(), $document, (string) $ctx->value('check'));
            Files::writeAtomic($output, $rendered[0], 0644);

            return ['written' => $output, 'status' => $document['status'], 'nagios_code' => $rendered[1]];
        }

        return ['document' => $document, 'check' => (string) $ctx->value('check')];
    }

    public function rawResult(array $data)
    {
        return isset($data['document']) ? Json::encode($data['document']) . "\n" : null;
    }

    public function renderText(array $data): string
    {
        return isset($data['document']) ? Formats::text($data['document']) : "Written to {$data['written']} (status {$data['status']}).\n";
    }

    public function renderFormat(string $format, array $data): array
    {
        if (!isset($data['document'])) {
            return ['', 'nagios' === $format ? $data['nagios_code'] : 0];
        }

        return $this->render($format, $data['document'], $data['check']);
    }

    /**
     * @param array<string, mixed> $document
     * @return array{0: string, 1: int}
     */
    private function render(string $format, array $document, string $check): array
    {
        if ('prometheus' === $format) {
            return [Formats::prometheus($document), 0];
        }
        if ('nagios' === $format) {
            return Formats::nagios($document, $check);
        }
        if ('json' === $format) {
            return [Json::encode($document) . "\n", 0];
        }

        return [Formats::text($document), 0];
    }

    /**
     * State file of --instance (instances.ini gives run_as), else of the instance found by
     * --dolpath or DOLPATH.
     *
     * @return array{0: string, 1: string}
     */
    private function locate(Context $ctx): array
    {
        $id = $ctx->value('instance');
        if (null !== $id) {
            if (!Instance::isValidId($id)) {
                throw Failure::usage('INSTANCE_INVALID', "Invalid instance identifier '{$id}'.");
            }
            $instance = InstancesIni::load()->get($id);
            if (null === $instance) {
                throw Failure::usage('INSTANCE_UNKNOWN', "Instance '{$id}' is not declared in " . Paths::instancesIni() . '.');
            }

            return [Paths::stateFile($instance->setting('run_as', Posix::currentUserName()), $id), $id];
        }
        $instance = Resolver::resolve(null, $ctx->value('dolpath'));
        $ctx->setInstance($instance);

        return [Paths::stateFile($instance->setting('run_as', Posix::currentUserName()), $instance->id()), $instance->id()];
    }
}
