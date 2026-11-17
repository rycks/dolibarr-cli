<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Store\Journal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'log', description: 'Journal of the actions of the pilot')]
final class LogCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::OPTIONAL, 'Only this instance', '')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of entries', '50')
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $entries = (new Journal())->tail(max(1, (int) $this->input->getOption('limit')), (string) $this->input->getArgument('target'));
        if ($this->isJson()) {
            $this->json($entries);

            return self::EXIT_OK;
        }
        foreach ($entries as $entry) {
            $this->output->writeln(sprintf(
                '%s %-9s %-24s %s %s',
                $entry['date'] ?? '',
                $entry['phase'] ?? '',
                self::clean((string) ($entry['instance'] ?? $entry['target'] ?? '')),
                self::clean((string) ($entry['command'] ?? '')),
                'result' === ($entry['phase'] ?? '') ? 'exit '.($entry['exit_code'] ?? '?').' '.self::clean((string) ($entry['summary'] ?? '')) : implode(' ', array_map('strval', (array) ($entry['arguments'] ?? [])))
            ));
        }

        return self::EXIT_OK;
    }
}
