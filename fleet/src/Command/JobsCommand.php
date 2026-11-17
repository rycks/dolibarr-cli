<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Remote\Outcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Long operations of the servers: list, or one job with its log and result. After an SSH cut,
 * the job goes on on the server: this command finds it again.
 */
#[AsCommand(name: 'jobs', description: 'Detached operations in progress or finished, with their log')]
final class JobsCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::OPTIONAL, 'Instance, server:<alias>, tag:<tag>, all', 'all')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'One job: its state, log and result')
            ->addOption('wait', null, InputOption::VALUE_NONE, 'With --id, wait until the job ends')
            ->addTargetOptions()
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $instances = $this->targets((string) $this->input->getArgument('target'));
        $id = (string) $this->input->getOption('id');
        $arguments = '' === $id ? ['job', 'status'] : ['job', 'status', 'id='.$id];
        do {
            $outcomes = $this->dolicli()->runMany($instances, $arguments, (int) $this->inventory()->default('parallel'));
            $running = false;
            foreach ($outcomes as $outcome) {
                $state = (string) ($outcome->data()['job']['state'] ?? '');
                $running = $running || in_array($state, ['starting', 'running'], true);
            }
            if ($running && $this->input->getOption('wait')) {
                sleep(2);
            }
        } while ($running && '' !== $id && $this->input->getOption('wait'));

        return $this->outcomes($outcomes, static function (Outcome $outcome): string {
            $data = $outcome->data();
            if (isset($data['job'])) {
                $job = $data['job'];
                $result = is_array($job['result'] ?? null) ? ' result: '.(true === ($job['result']['ok'] ?? false) ? 'ok' : (string) ($job['result']['errors'][0]['message'] ?? 'failed')) : '';

                return (string) $job['state'].(isset($job['exit_code']) ? ' (exit '.$job['exit_code'].')' : '').$result;
            }
            $lines = [];
            foreach ((array) ($data['jobs'] ?? []) as $job) {
                $lines[] = (string) ($job['id'] ?? '?').' '.(string) ($job['state'] ?? '?').' '.(string) ($job['command'] ?? '');
            }

            return [] === $lines ? 'no job' : implode(' | ', $lines);
        });
    }
}
