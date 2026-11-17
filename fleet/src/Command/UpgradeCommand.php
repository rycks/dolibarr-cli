<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\InventoryException;
use Dolifleet\Store\Journal;
use Dolifleet\Upgrade\Planner;
use Dolifleet\Upgrade\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use RuntimeException;

/**
 * Upgrade of one instance: plan (computed and shown, saved locally), run, resume, status.
 * Destructive: one instance at a time, its name typed again before the run.
 */
#[AsCommand(name: 'upgrade', description: 'Upgrade plan, run, resume or status of one instance')]
final class UpgradeCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'plan, run, resume or status')
            ->addArgument('instance', InputArgument::REQUIRED, 'One instance')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target version X.Y.Z (plan, run)')
            ->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Local archive of the target instead of downloading it')
            ->addOption('signature', null, InputOption::VALUE_REQUIRED, 'Local official signature filelist-X.Y.Z.xml instead of downloading it')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'No confirmation: manual steps are considered done (scripts; journaled)')
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $action = (string) $this->input->getArgument('action');
        $instance = $this->inventory()->instance((string) $this->input->getArgument('instance'));
        $file = Runner::planFile($instance->id);

        if ('status' === $action) {
            $local = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $remote = $this->dolicli()->run($instance, ['upgrade', 'status']);
            $status = ['local_plan' => $local, 'server' => $remote->ok() ? $remote->data() : ['error' => $remote->error]];
            if ($this->isJson()) {
                $this->json($status);
            } else {
                $this->showPlan(is_array($local) ? $local : (array) ($status['server']['plan'] ?? []));
            }

            return $remote->ok() ? self::EXIT_OK : self::EXIT_FAILED;
        }

        if ('plan' === $action || ('run' === $action && null !== $this->input->getOption('to'))) {
            $to = (string) $this->input->getOption('to');
            if ('' === $to) {
                throw new InventoryException('Give the target version: --to=X.Y.Z.');
            }
            $plan = (new Planner($this->dolicli()))->plan($instance, $to);
            if ( ! is_dir(dirname($file)) && ! mkdir(dirname($file), 0700, true) && ! is_dir(dirname($file))) {
                throw new RuntimeException('Cannot create '.dirname($file));
            }
            file_put_contents($file, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            if ('plan' === $action) {
                if ($this->isJson()) {
                    $this->json($plan);
                } else {
                    $this->showPlan($plan);
                    $this->output->writeln('Saved in '.$file.'. Run it with: dolifleet upgrade run '.$instance->id);
                }

                return [] === $plan['blockers'] ? self::EXIT_OK : self::EXIT_FAILED;
            }
        }

        if ( ! in_array($action, ['run', 'resume'], true)) {
            throw new InventoryException('Action must be plan, run, resume or status.');
        }
        if ( ! is_file($file)) {
            throw new InventoryException("No plan for {$instance->id}: dolifleet upgrade plan {$instance->id} --to=X.Y.Z");
        }
        $plan = json_decode((string) file_get_contents($file), true);
        if ( ! is_array($plan)) {
            throw new RuntimeException("Unreadable plan {$file}");
        }
        $this->showPlan($plan);
        $yes = (bool) $this->input->getOption('yes');
        if ( ! $yes) {
            $answer = $this->ask("Upgrade {$instance->id} from {$plan['from']} to {$plan['to']}: type the name of the instance to confirm: ");
            if (null === $answer) {
                throw new InventoryException('Confirmation required: run in a terminal, or add --yes (journaled).');
            }
            if (trim($answer) !== $instance->id) {
                throw new InventoryException('Not confirmed: nothing was done.');
            }
        }

        $runner = new Runner(
            $this->dolicli(),
            $instance,
            $plan,
            fn (string $message) => $this->progress($message),
            function (string $question) use ($yes): bool {
                if ($yes) {
                    $this->progress('Manual step considered done (--yes): '.$question);

                    return true;
                }

                return 'yes' === trim((string) $this->ask($question.' Type yes when done, anything else to pause: '));
            },
            new Journal(),
            array_filter(['archive' => (string) $this->input->getOption('archive'), 'signature' => (string) $this->input->getOption('signature')])
        );
        $state = $runner->run();
        if ($this->isJson()) {
            $this->json(['state' => $state, 'plan' => $runner->plan()]);
        } else {
            $this->output->writeln('Upgrade '.$state.'.');
        }

        return Runner::DONE === $state || Runner::PAUSED === $state ? self::EXIT_OK : self::EXIT_FAILED;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function showPlan(array $plan): void
    {
        if ($this->isJson() || [] === $plan) {
            return;
        }
        $this->output->writeln(sprintf('<info>%s</info>: %s -> %s, PHP CLI %s', self::clean((string) ($plan['instance'] ?? '')), self::clean((string) ($plan['from'] ?? '')), self::clean((string) ($plan['to'] ?? '')), self::clean((string) ($plan['php_cli'] ?? ''))));
        foreach ((array) ($plan['blockers'] ?? []) as $blocker) {
            $this->output->writeln('  <error>blocker</error> '.self::clean((string) $blocker));
        }
        foreach ((array) ($plan['warnings'] ?? []) as $warning) {
            $this->output->writeln('  <comment>warning</comment> '.self::clean((string) $warning));
        }
        foreach ((array) ($plan['steps'] ?? []) as $step) {
            $state = (string) ($plan['progress'][$step['id']]['state'] ?? 'pending');
            $this->output->writeln(sprintf('  %-8s %-13s %s', $state, self::clean((string) $step['id']), self::clean((string) $step['description'])));
        }
    }
}
