<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Remote\Outcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'sessions', description: 'Active sessions of a target, with the logins')]
final class SessionsCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Instance, server:<alias>, tag:<tag>, all')->addTargetOptions()->addJsonOption();
    }

    protected function handle(): int
    {
        $outcomes = $this->dolicli()->runMany($this->targets((string) $this->input->getArgument('target')), ['system', 'sessions', '--details'], (int) $this->inventory()->default('parallel'));

        return $this->outcomes($outcomes, static function (Outcome $outcome): string {
            $data = $outcome->data();
            $logins = array_map(static fn ($s): string => (string) ($s['login'] ?? '?'), (array) ($data['sessions'] ?? []));

            return (int) ($data['count'] ?? 0).' sessions'.([] === $logins ? '' : ': '.implode(', ', $logins));
        });
    }
}
