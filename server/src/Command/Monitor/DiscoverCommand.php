<?php

namespace Dolicli\Command\Monitor;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Json;
use Dolicli\Instance\InstancesIni;
use Dolicli\Monitor\State;
use Dolicli\System\Paths;
use Dolicli\System\Posix;

/**
 * Low-level discovery of Zabbix: one entry per instance of instances.ini, with the real
 * path of its state file. Runs under the monitoring account (UserParameter).
 */
final class DiscoverCommand extends Command
{
    public function name(): string
    {
        return 'monitor discover';
    }

    public function summary(): string
    {
        return 'Zabbix low-level discovery of the instances ({#INSTANCE}, {#URL}, {#STATEFILE})';
    }

    public function needsDolibarr(): bool
    {
        return false;
    }

    public function allowedAsRoot(): bool
    {
        return true;
    }

    public function help(): string
    {
        return 'Writes the discovery JSON expected by Zabbix ({"data": [...]}) whatever the format. The URL comes from the state file.';
    }

    public function execute(Context $ctx): array
    {
        $entries = [];
        foreach (InstancesIni::load()->all() as $id => $instance) {
            $file = Paths::stateFile($instance->setting('run_as', Posix::currentUserName()), $id);
            $state = State::read($file);
            $entries[] = [
                '{#INSTANCE}' => $id,
                '{#URL}' => isset($state['instance']['url']) ? (string) $state['instance']['url'] : '',
                '{#STATEFILE}' => $file,
            ];
        }

        return ['data' => $entries];
    }

    /**
     * @param array<string, mixed> $data
     * @return string
     */
    public function rawResult(array $data)
    {
        return Json::encode(['data' => $data['data']], false) . "\n";
    }
}
