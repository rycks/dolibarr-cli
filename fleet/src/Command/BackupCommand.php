<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\InventoryException;
use Dolifleet\Remote\Outcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Backup as a long operation: started detached on each server, followed with dolifleet jobs.
 */
#[AsCommand(name: 'backup', description: 'Back up the database, documents or modules of a target (detached job)')]
final class BackupCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Instance, server:<alias>, tag:<tag>, all')
            ->addOption('database', null, InputOption::VALUE_NONE, 'Dump the database')
            ->addOption('documents', null, InputOption::VALUE_NONE, 'Zip documents/')
            ->addOption('modules', null, InputOption::VALUE_NONE, 'Zip the external modules')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Destination directory on the server')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'No confirmation (scripts; journaled)')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Go on with the other instances after a failure')
            ->addTargetOptions()
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $arguments = ['backup'];
        foreach (['database', 'documents', 'modules'] as $part) {
            if ($this->input->getOption($part)) {
                $arguments[] = '--'.$part;
            }
        }
        if (1 === count($arguments)) {
            throw new InventoryException('Choose at least one of --database, --documents, --modules.');
        }
        $dir = (string) $this->input->getOption('dir');
        if ('' !== $dir) {
            if (1 !== preg_match('#^/[A-Za-z0-9._/+@-]*$#', $dir)) {
                throw new InventoryException('--dir must be an absolute path without shell characters.');
            }
            $arguments[] = 'dir='.$dir;
        }
        $target = (string) $this->input->getArgument('target');
        $outcomes = $this->batch()->run($this->targets($target), $arguments, [
            'yes' => (bool) $this->input->getOption('yes'),
            'continue_on_error' => (bool) $this->input->getOption('continue-on-error'),
            'target' => $target,
            'detach' => true,
        ]);

        return $this->outcomes($outcomes, static fn (Outcome $outcome): string => 'job '.(string) ($outcome->data()['id'] ?? '?').' started: dolifleet jobs '.$outcome->instance->id);
    }
}
