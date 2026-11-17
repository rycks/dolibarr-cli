<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\Inventory;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Paths;
use Dolifleet\Remote\HostKey;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use RuntimeException;

/**
 * Assistant: SSH alias, host key, discovery of the instances, choice, tags. Appends a server
 * block to the inventory (as text: the comments of the file are kept).
 */
#[AsCommand(name: 'add', description: 'Add a server and its instances to the inventory (assistant)')]
final class AddCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('server', InputArgument::REQUIRED, 'SSH alias of the server (~/.ssh/config)')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Directory to search', '/var/www')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP CLI of the server', 'php')
            ->addOption('sudo', null, InputOption::VALUE_NONE, 'Search as root (sudo -n)')
            ->addOption('instances', null, InputOption::VALUE_REQUIRED, 'Identifiers to add, comma separated (default: ask, or all)')
            ->addOption('tags', null, InputOption::VALUE_REQUIRED, 'Tags of the added instances, comma separated', '')
            ->addOption('accept-host-key', null, InputOption::VALUE_NONE, 'Add an unknown host key without asking (fingerprint still shown)');
    }

    protected function handle(): int
    {
        $server = (string) $this->input->getArgument('server');
        if (1 !== preg_match(Inventory::SERVER_PATTERN, $server)) {
            throw new InventoryException("Invalid server alias '{$server}'.");
        }
        $file = Paths::inventory();
        $existing = is_file($file) ? Inventory::load($file) : null;
        if (null !== $existing && isset($existing->servers()[$server])) {
            throw new InventoryException("Server '{$server}' is already in the inventory.");
        }

        if ( ! $this->hostKey($server)) {
            return self::EXIT_UNREACHABLE;
        }

        $php = (string) $this->input->getOption('php');
        $discovery = $this->discoverWith($server, $php);
        if ( ! $discovery['ok']) {
            $this->error($discovery['error']);

            return self::EXIT_FAILED;
        }
        foreach ($discovery['warnings'] as $warning) {
            $this->output->writeln('<comment>'.self::clean($warning).'</comment>');
        }
        $instances = [];
        foreach ($discovery['instances'] as $instance) {
            $instances[(string) $instance['id']] = $instance;
        }
        if ([] === $instances) {
            $this->error('No Dolibarr instance found under '.$this->input->getOption('root').' on '.$server
                .': give --root the installation directory or its htdocs, or search as root with --sudo.');

            return self::EXIT_FAILED;
        }
        foreach ($instances as $id => $instance) {
            $this->output->writeln(sprintf('  %-24s %-10s %s (run_as %s)', self::clean($id), self::clean((string) $instance['version']), self::clean((string) $instance['htdocs']), self::clean((string) $instance['documents_owner'])));
        }

        $chosen = (string) $this->input->getOption('instances');
        if ('' === $chosen) {
            $chosen = (string) ($this->ask('Instances to add, comma separated ['.implode(',', array_keys($instances)).']: ') ?? '');
            $chosen = '' === trim($chosen) ? implode(',', array_keys($instances)) : $chosen;
        }
        $tags = (string) $this->input->getOption('tags');
        $selected = [];
        foreach (array_map('trim', explode(',', $chosen)) as $id) {
            if ( ! isset($instances[$id])) {
                throw new InventoryException("Instance '{$id}' was not found on {$server}.");
            }
            if (null !== $existing && isset($existing->instances()[$id])) {
                throw new InventoryException("Instance '{$id}' is already in the inventory.");
            }
            $selected[$id] = $instances[$id];
        }

        $block = "  {$server}:\n    php: ".$this->yaml($php)."\n    instances:\n";
        foreach ($selected as $id => $instance) {
            $block .= "      {$id}:\n        htdocs: ".$this->yaml((string) $instance['htdocs'])."\n";
            if ('' !== (string) $instance['documents_owner'] && 'root' !== $instance['documents_owner']) {
                $block .= '        run_as: '.$this->yaml((string) $instance['documents_owner'])."\n";
            }
            if ('' !== $tags) {
                $block .= '        tags: ['.implode(', ', array_map(fn ($t) => $this->yaml(trim($t)), explode(',', $tags)))."]\n";
            }
        }
        $this->append($file, $block);
        Inventory::load($file);
        $this->output->writeln('<info>Added</info> '.count($selected).' instance(s) of '.$server.' to '.$file.'. Next: dolifleet check server:'.$server);

        return self::EXIT_OK;
    }

    /**
     * @return array{ok: bool, reached: bool, error: string, instances: list<array<string, mixed>>, warnings: list<string>}
     */
    private function discoverWith(string $server, string $php): array
    {
        $outcome = $this->dolicliForServer()->runOnServer($server, $php, ['instance', 'discover', 'root='.(string) $this->input->getOption('root')], (bool) $this->input->getOption('sudo'));
        if ( ! $outcome->ok()) {
            return ['ok' => false, 'reached' => $outcome->reached, 'error' => $outcome->error, 'instances' => [], 'warnings' => []];
        }

        $warnings = [];
        foreach ((array) ($outcome->envelope['warnings'] ?? []) as $warning) {
            $warnings[] = (string) ($warning['message'] ?? '');
        }

        return ['ok' => true, 'reached' => true, 'error' => '', 'instances' => array_values((array) ($outcome->data()['instances'] ?? [])), 'warnings' => $warnings];
    }

    private function dolicliForServer(): \Dolifleet\Remote\Dolicli
    {
        $file = Paths::inventory();
        $inventory = is_file($file) ? Inventory::load($file) : Inventory::empty();

        return new \Dolifleet\Remote\Dolicli($inventory, $this->transport(), $this->body());
    }

    /**
     * BatchMode refuses unknown host keys: show the fingerprint and ask before adding it.
     */
    private function hostKey(string $server): bool
    {
        $hostKey = new HostKey($this->transport());
        $probe = $hostKey->probe($server);
        if (HostKey::KNOWN === $probe['state']) {
            return true;
        }
        if (HostKey::UNREACHABLE === $probe['state']) {
            $this->error($probe['error']);

            return false;
        }
        try {
            $keys = $hostKey->scan($server);
            $fingerprints = $hostKey->fingerprints($keys);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return false;
        }
        $this->output->writeln("Host key of {$server}:\n".self::clean(implode("\n", $fingerprints)));
        if ( ! $this->input->getOption('accept-host-key')) {
            $answer = $this->ask('Is this the key of '.$server.'? Type yes to add it to known_hosts: ');
            if ('yes' !== trim((string) $answer)) {
                $this->error('Host key not accepted: nothing was added.');

                return false;
            }
        }
        $hostKey->accept($keys);

        return true;
    }

    private function append(string $file, string $block): void
    {
        Paths::ensure(dirname($file));
        $content = is_file($file) ? (string) file_get_contents($file) : "# Inventory of dolifleet: servers are SSH aliases of ~/.ssh/config.\n\ndefaults:\n  parallel: 4\n  ssh_timeout: 10\n  protected_tags: [prod]\n\n";
        if (1 === preg_match('/^servers:[ \t]*(\{\})?[ \t]*$/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            // Right after "servers:", whatever follows (include, other keys).
            $end = $m[0][1] + mb_strlen($m[0][0]);
            $content = mb_substr($content, 0, $m[0][1])."servers:\n".$block.ltrim(mb_substr($content, $end), "\n");
        } else {
            $content = rtrim($content)."\n\nservers:\n".$block;
        }
        $tmp = $file.'.tmp';
        if (false === file_put_contents($tmp, $content) || ! rename($tmp, $file)) {
            throw new RuntimeException("Cannot write {$file}");
        }
        @chmod($file, 0600);
    }

    private function yaml(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
