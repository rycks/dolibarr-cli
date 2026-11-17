<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\Instance;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Remote\Outcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'maintenance', description: 'Maintenance mode on or off on a target, with the guards of the writes')]
final class MaintenanceCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'on or off')
            ->addArgument('target', InputArgument::REQUIRED, 'Instance, server:<alias>, tag:<tag>, all')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'No confirmation (scripts; journaled)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Describe the actions without doing them')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Go on with the other instances after a failure')
            ->addTargetOptions()
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $action = (string) $this->input->getArgument('action');
        if ( ! in_array($action, ['on', 'off'], true)) {
            throw new InventoryException('Action must be on or off.');
        }
        $target = (string) $this->input->getArgument('target');
        $options = [
            'yes' => (bool) $this->input->getOption('yes'),
            'dry_run' => (bool) $this->input->getOption('dry-run'),
            'continue_on_error' => (bool) $this->input->getOption('continue-on-error'),
            'target' => $target,
        ];
        if ('on' === $action) {
            // The login allowed during the maintenance comes from the inventory (maintenance_login).
            $options['extra'] = static fn (Instance $instance): array => '' !== $instance->maintenanceLogin ? ['login='.$instance->maintenanceLogin] : [];
        }
        $outcomes = $this->batch()->run($this->targets($target), ['system', 'maintenance', 'on' === $action ? 'enable' : 'disable'], $options);

        return $this->outcomes($outcomes, static function (Outcome $outcome): string {
            $data = $outcome->data();
            if ( ! empty($data['dry_run'])) {
                return 'dry run: '.implode('; ', array_map('strval', (array) $data['actions']));
            }

            return ! empty($data['enabled']) ? 'maintenance on, only '.(string) ($data['allowed_login'] ?? '?').' can log in' : 'maintenance off';
        });
    }
}
