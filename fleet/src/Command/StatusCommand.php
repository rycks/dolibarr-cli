<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\Instance;
use Dolifleet\Remote\Outcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Table of the fleet: the state files when they are recent enough (threshold: three times the
 * fast interval), otherwise an ephemeral collection of the fast checks only. Always shows the
 * age of the data.
 */
#[AsCommand(name: 'status', description: 'State of the instances (state files, or fresh fast checks)')]
final class StatusCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Instance, server:<alias>, tag:<tag>, all')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Collect the fast checks now instead of reading the state files')
            ->addTargetOptions()
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $instances = $this->targets((string) $this->input->getArgument('target'));
        $states = $this->collect($instances, (bool) $this->input->getOption('refresh'));
        $exit = self::EXIT_OK;
        $reached = false;
        foreach ($states as $state) {
            $reached = $reached || $state['reached'];
            if ( ! $state['ok']) {
                $exit = self::EXIT_FAILED;
            }
        }
        if ( ! $reached) {
            $exit = self::EXIT_UNREACHABLE;
        }
        if ($this->isJson()) {
            $this->json(['exit_code' => $exit, 'instances' => $states]);

            return $exit;
        }
        $table = new Table($this->output);
        $table->setHeaders(['Instance', 'Status', 'Version', 'Maintenance', 'Sessions', 'Backup age', 'Filecheck', 'Data age', 'Source']);
        foreach ($states as $id => $state) {
            $checks = $state['document']['checks'] ?? [];
            $table->addRow([
                $id,
                $state['ok'] ? self::clean((string) ($state['document']['status'] ?? '?')) : 'error: '.self::clean($state['error']),
                self::clean((string) ($checks['dolibarr.version']['value'] ?? '')),
                isset($checks['maintenance.enabled']['value']) ? ($checks['maintenance.enabled']['value'] ? 'on' : 'off') : '',
                self::clean((string) ($checks['sessions.count']['value'] ?? '')),
                isset($checks['backup.age']['value']) ? sprintf('%.1f d', (int) $checks['backup.age']['value'] / 86400) : '',
                isset($checks['filecheck.modified']['value']) ? (int) $checks['filecheck.modified']['value'].' mod., '.(int) ($checks['filecheck.missing']['value'] ?? 0).' miss.' : '',
                null === $state['age'] ? '' : $state['age'].' s',
                $state['source'],
            ]);
        }
        $table->render();

        return $exit;
    }

    /**
     * @param array<string, Instance> $instances
     * @return array<string, array{ok: bool, reached: bool, error: string, source: string, age: ?int, document: array<string, mixed>}>
     */
    public function collect(array $instances, bool $refresh): array
    {
        $parallel = (int) $this->inventory()->default('parallel');
        $maxAge = 3 * (int) $this->inventory()->default('fast_interval');
        $states = [];
        $toCollect = $instances;
        if ( ! $refresh) {
            // monitor show finds the state file from --dolpath: /var/lib/dolicli/<run_as>/<instance>.json.
            $results = $this->dolicli()->runMany($instances, ['monitor', 'show'], $parallel);
            foreach ($results as $id => $outcome) {
                $document = $outcome->ok() ? $outcome->data() : [];
                $age = isset($document['generated_at_unix']) ? time() - (int) $document['generated_at_unix'] : null;
                if (null !== $age && $age <= $maxAge) {
                    $states[$id] = $this->state($outcome, 'state file', $age);
                    unset($toCollect[$id]);
                }
            }
        }
        if ([] !== $toCollect) {
            foreach ($this->dolicli()->runMany($toCollect, ['monitor', 'collect', '--stdout'], $parallel) as $id => $outcome) {
                $states[$id] = $this->state($outcome, 'collected now', $outcome->ok() ? 0 : null);
            }
        }
        $ordered = [];
        foreach (array_keys($instances) as $id) {
            $ordered[$id] = $states[$id];
            if ($states[$id]['ok']) {
                $this->store()->record($id, $states[$id]['document']);
            }
        }

        return $ordered;
    }

    /**
     * @return array{ok: bool, reached: bool, error: string, source: string, age: ?int, document: array<string, mixed>}
     */
    private function state(Outcome $outcome, string $source, ?int $age): array
    {
        return [
            'ok' => $outcome->ok(),
            'reached' => $outcome->reached,
            'error' => $outcome->error,
            'source' => $source,
            'age' => $age,
            'document' => $outcome->ok() ? $outcome->data() : [],
        ];
    }
}
