<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\Instance;
use Dolifleet\Store\Journal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Install or update dolicli in the installed mode on the servers of a target: body sent with its
 * SHA256, written to a temporary file then renamed, php -l before activation, instances.ini
 * written from the inventory, cron of the collections. Needs root (sudo rule or root login).
 */
#[AsCommand(name: 'setup', description: 'Install or update dolicli (installed mode) on the servers of a target')]
final class SetupCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Instance, server:<alias>, tag:<tag>, all')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace a newer installed version')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'No confirmation (scripts; journaled)')
            ->addTargetOptions()
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $instances = $this->targets((string) $this->input->getArgument('target'));
        $servers = [];
        foreach ($instances as $instance) {
            $servers[$instance->server] = true;
        }
        // instances.ini lists every instance of the server, not only the targeted ones.
        $byServer = [];
        foreach ($this->inventory()->instances() as $instance) {
            if (isset($servers[$instance->server])) {
                $byServer[$instance->server][$instance->id] = $instance;
            }
        }
        if ( ! $this->input->getOption('yes')) {
            $answer = $this->ask('Install dolicli '.$this->body()->version().' as root on '.implode(', ', array_keys($servers)).'? Type yes to confirm: ');
            if (null === $answer) {
                $this->error('Confirmation required: run in a terminal, or add --yes (journaled).');

                return self::EXIT_USAGE;
            }
            if ('yes' !== trim($answer)) {
                $this->error('Not confirmed: nothing was done.');

                return self::EXIT_USAGE;
            }
        }

        $journal = new Journal();
        $run = date('YmdHis').'-'.bin2hex(random_bytes(3));
        $results = [];
        $exit = self::EXIT_OK;
        $reached = false;
        foreach ($byServer as $server => $serverInstances) {
            $journal->intention($run, 'server:'.$server, 'setup install', ['--from-stream'], [], ['version' => $this->body()->version(), 'yes' => (bool) $this->input->getOption('yes')]);
            $php = (string) ($this->inventory()->servers()[$server]['php'] ?: (reset($serverInstances)->php ?: 'php'));
            $arguments = ['setup', 'install', '--from-stream', 'sha256='.$this->body()->sha256(), '--yes'];
            if ($this->input->getOption('force')) {
                $arguments[] = '--force';
            }
            $outcome = $this->dolicli()->runOnServer($server, $php, $arguments, true, [
                'body' => $this->body()->content(),
                'launcher' => (string) file_get_contents(dirname(__DIR__, 3).'/server/launcher.php'),
                'instances.ini' => self::instancesIni($serverInstances),
            ]);
            $journal->result($run, 'server:'.$server, 'setup install', $outcome->exitCode, $outcome->ok() ? 'ok' : $outcome->error);
            $reached = $reached || $outcome->reached;
            if ( ! $outcome->ok()) {
                $exit = self::EXIT_FAILED;
            }
            $results[$server] = ['ok' => $outcome->ok(), 'error' => $outcome->error, 'actions' => $outcome->data()['actions'] ?? [], 'warnings' => $outcome->envelope['warnings'] ?? []];
        }
        if ( ! $reached) {
            $exit = self::EXIT_UNREACHABLE;
        }
        if ($this->isJson()) {
            $this->json(['exit_code' => $exit, 'servers' => $results]);

            return $exit;
        }
        foreach ($results as $server => $result) {
            $this->output->writeln(sprintf('%-24s %s', $server, $result['ok'] ? '<info>installed</info>' : '<error>'.self::clean($result['error']).'</error>'));
            foreach ((array) $result['warnings'] as $warning) {
                $this->output->writeln('  <comment>'.self::clean((string) ($warning['message'] ?? '')).'</comment>');
            }
        }

        return $exit;
    }

    /**
     * /etc/dolicli/instances.ini of a server, from the inventory.
     *
     * @param array<string, Instance> $instances
     */
    public static function instancesIni(array $instances): string
    {
        $out = "; Instances managed by dolicli. Written by dolifleet setup from the inventory: edit the inventory.\n";
        foreach ($instances as $instance) {
            $out .= "\n[{$instance->id}]\n";
            foreach (['htdocs' => $instance->htdocs, 'run_as' => $instance->runAs, 'php' => $instance->php, 'web_php' => $instance->webPhp, 'dolibarr_user' => $instance->dolibarrUser, 'maintenance_login' => $instance->maintenanceLogin] as $key => $value) {
                if ('' !== $value) {
                    $out .= $key.' = "'.$value."\"\n";
                }
            }
        }

        return $out;
    }
}
