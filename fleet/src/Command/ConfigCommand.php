<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\InventoryException;
use Dolifleet\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'config', description: 'Validate the inventory and its includes (config validate)')]
final class ConfigCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'validate')->addJsonOption();
    }

    protected function handle(): int
    {
        if ('validate' !== $this->input->getArgument('action')) {
            throw new InventoryException('Unknown action: dolifleet config validate.');
        }
        $inventory = $this->inventory();
        $summary = [
            'inventory' => Paths::inventory(),
            'files' => $inventory->files(),
            'servers' => count($inventory->servers()),
            'instances' => count($inventory->instances()),
            'protected_tags' => $inventory->protectedTags(),
        ];
        if ($this->isJson()) {
            $this->json(['valid' => true] + $summary);
        } else {
            $this->output->writeln(sprintf('<info>Inventory valid</info>: %d servers, %d instances, %d file(s).', $summary['servers'], $summary['instances'], count($summary['files'])));
        }

        return self::EXIT_OK;
    }
}
