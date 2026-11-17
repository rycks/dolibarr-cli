<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Remote\Outcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'exec', description: 'Run a dolicli command on a target, with the guards of the manifest')]
final class ExecCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Instance, server:<alias>, tag:<tag>, all')
            ->addArgument('words', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'dolicli command and its arguments, after --')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'No confirmation (scripts; journaled)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Describe the actions without doing them')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Go on with the other instances after a failure')
            ->addTargetOptions()
            ->addConfirmOption()
            ->addJsonOption()
            ->setHelp("Example: dolifleet exec tag:preprod -- user list\nWrites run in series, the first instance as a witness; destructive commands are refused on several instances.");
    }

    protected function handle(): int
    {
        $target = (string) $this->input->getArgument('target');
        $outcomes = $this->batch()->run($this->targets($target), array_values((array) $this->input->getArgument('words')), [
            'yes' => (bool) $this->input->getOption('yes'),
            'answer' => $this->confirmAnswer(),
            'dry_run' => (bool) $this->input->getOption('dry-run'),
            'continue_on_error' => (bool) $this->input->getOption('continue-on-error'),
            'target' => $target,
        ]);

        return $this->outcomes($outcomes, static function (Outcome $outcome): string {
            $data = $outcome->data();
            if (isset($data['actions']) && is_array($data['actions'])) {
                return 'dry run: '.implode('; ', array_map('strval', $data['actions']));
            }

            return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        });
    }
}
