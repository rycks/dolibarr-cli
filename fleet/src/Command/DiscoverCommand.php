<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'discover', description: 'List the Dolibarr instances found on a server, without changing the inventory')]
final class DiscoverCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::REQUIRED, 'SSH alias of the server')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Directory to search', '/var/www')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP CLI of the server', 'php')
            ->addOption('sudo', null, InputOption::VALUE_NONE, 'Search as root (sudo -n): needed to read conf.php files in mode 0400')
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $server = (string) $this->input->getArgument('server');
        $outcome = $this->discover($server, (string) $this->input->getOption('root'), (string) $this->input->getOption('php'), (bool) $this->input->getOption('sudo'));
        if ( ! $outcome['ok']) {
            $this->error($outcome['error']);

            return $outcome['reached'] ? self::EXIT_FAILED : self::EXIT_UNREACHABLE;
        }
        if ($this->isJson()) {
            $this->json($outcome['instances']);

            return self::EXIT_OK;
        }
        foreach ($outcome['instances'] as $instance) {
            $this->output->writeln(sprintf(
                '%-24s %-10s %s  documents=%s  run_as=%s',
                self::clean((string) $instance['id']),
                self::clean((string) $instance['version']),
                self::clean((string) $instance['htdocs']),
                self::clean((string) $instance['documents']),
                self::clean((string) $instance['documents_owner'])
            ));
        }

        return self::EXIT_OK;
    }

    /**
     * @return array{ok: bool, reached: bool, error: string, instances: list<array<string, mixed>>}
     */
    public function discover(string $server, string $root, string $php, bool $sudo): array
    {
        $outcome = $this->dolicli()->runOnServer($server, $php, ['instance', 'discover', 'root='.$root], $sudo);
        if ( ! $outcome->ok()) {
            return ['ok' => false, 'reached' => $outcome->reached, 'error' => $outcome->error, 'instances' => []];
        }

        return ['ok' => true, 'reached' => true, 'error' => '', 'instances' => array_values((array) ($outcome->data()['instances'] ?? []))];
    }
}
