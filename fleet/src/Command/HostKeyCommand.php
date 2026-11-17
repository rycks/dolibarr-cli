<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\Inventory;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Remote\HostKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use RuntimeException;

/**
 * Host key of a server, without any question: tells whether it is known, gives the fingerprints of
 * an unknown key, adds it to known_hosts with --accept. Front ends ask the confirmation themselves.
 */
#[AsCommand(name: 'hostkey', description: 'Check the SSH host key of a server, show its fingerprint, add it to known_hosts')]
final class HostKeyCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::REQUIRED, 'SSH alias of the server (~/.ssh/config)')
            ->addOption('accept', null, InputOption::VALUE_NONE, 'Add an unknown host key to known_hosts')
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $server = (string) $this->input->getArgument('server');
        if (1 !== preg_match(Inventory::SERVER_PATTERN, $server)) {
            throw new InventoryException("Invalid server alias '{$server}'.");
        }
        $report = ['server' => $server, 'known' => false, 'fingerprints' => [], 'accepted' => false, 'error' => ''];
        $hostKey = new HostKey($this->transport());

        $probe = $hostKey->probe($server);
        if (HostKey::KNOWN === $probe['state']) {
            $report['known'] = true;

            return $this->report($report, self::EXIT_OK);
        }
        if (HostKey::UNREACHABLE === $probe['state']) {
            $report['error'] = $probe['error'];

            return $this->report($report, self::EXIT_UNREACHABLE);
        }

        try {
            $keys = $hostKey->scan($server);
            $report['fingerprints'] = $hostKey->fingerprints($keys);
        } catch (RuntimeException $e) {
            $report['error'] = $e->getMessage();

            return $this->report($report, self::EXIT_UNREACHABLE);
        }
        if ($this->input->getOption('accept')) {
            try {
                $hostKey->accept($keys);
            } catch (RuntimeException $e) {
                $report['error'] = $e->getMessage();

                return $this->report($report, self::EXIT_FAILED);
            }
            $report['accepted'] = true;
        }

        return $this->report($report, self::EXIT_OK);
    }

    /**
     * @param array{server: string, known: bool, fingerprints: list<string>, accepted: bool, error: string} $report
     */
    private function report(array $report, int $exit): int
    {
        if ($this->isJson()) {
            $this->json($report);

            return $exit;
        }
        $server = $report['server'];
        if ('' !== $report['error']) {
            $this->error($report['error']);
        } elseif ($report['known']) {
            $this->output->writeln('<info>Known</info> host key: the connection to '.$server.' works.');
        } else {
            $this->output->writeln('Host key of '.$server.':');
            foreach ($report['fingerprints'] as $fingerprint) {
                $this->output->writeln('  '.self::clean($fingerprint));
            }
            $this->output->writeln($report['accepted']
                ? '<info>Added</info> to known_hosts.'
                : 'Not in known_hosts. Check it with the administrator of the server, then: dolifleet hostkey '.$server.' --accept');
        }

        return $exit;
    }
}
