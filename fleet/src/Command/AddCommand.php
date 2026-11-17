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
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
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
            ->addOption('accept-host-key', null, InputOption::VALUE_NONE, 'Add an unknown host key without asking (fingerprint still shown)')
            ->addOption('rename', null, InputOption::VALUE_REQUIRED, 'Other identifiers for found instances: <found-id>=<new-id>, comma separated', '')
            ->addOption('prod', null, InputOption::VALUE_REQUIRED, 'Found instances that also get the first protected tag, comma separated', '')
            ->addJsonOption();
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
        // Options checked before connecting: a typo costs no SSH round trip.
        $renames = $this->renames();
        $prod = self::ids((string) $this->input->getOption('prod'));
        $tags = $this->tags();
        $protected = '';
        if ([] !== $prod) {
            $protected = ($existing ?? Inventory::empty())->protectedTags()[0] ?? '';
            if ('' === $protected) {
                throw new InventoryException('--prod needs a tag in protected_tags (defaults of the inventory), which is empty.');
            }
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
            $this->say('<comment>'.self::clean($warning).'</comment>');
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
            $this->say(sprintf('  %-24s %-10s %s (run_as %s)', self::clean((string) $id), self::clean((string) $instance['version']), self::clean((string) $instance['htdocs']), self::clean((string) $instance['documents_owner'])));
        }

        $chosen = (string) $this->input->getOption('instances');
        if ('' === $chosen) {
            $chosen = (string) (($this->isJson() ? null : $this->ask('Instances to add, comma separated ['.implode(',', array_keys($instances)).']: ')) ?? '');
            $chosen = '' === trim($chosen) ? implode(',', array_keys($instances)) : $chosen;
        }
        $selected = [];
        foreach (array_map('trim', explode(',', $chosen)) as $id) {
            if ( ! isset($instances[$id])) {
                throw new InventoryException("Instance '{$id}' was not found on {$server}.");
            }
            $selected[$id] = $instances[$id];
        }
        foreach (['--rename' => array_keys($renames), '--prod' => $prod] as $option => $ids) {
            foreach ($ids as $id) {
                if ( ! isset($selected[$id])) {
                    throw new InventoryException("Instance '{$id}' of {$option} is not among the instances to add (found: ".implode(', ', array_keys($instances)).').');
                }
            }
        }

        $added = [];
        $block = '  '.$this->key($server).":\n    php: ".$this->yaml($php)."\n    instances:\n";
        foreach ($selected as $found => $instance) {
            $found = (string) $found;
            $id = $renames[$found] ?? $found;
            $this->checkNewId($id, $found, $existing, $added);
            $instanceTags = array_values(array_unique(in_array($found, $prod, true) ? array_merge([$protected], $tags) : $tags));
            $block .= '      '.$this->key($id).":\n        htdocs: ".$this->yaml((string) $instance['htdocs'])."\n";
            if ('' !== (string) $instance['documents_owner'] && 'root' !== $instance['documents_owner']) {
                $block .= '        run_as: '.$this->yaml((string) $instance['documents_owner'])."\n";
            }
            if ([] !== $instanceTags) {
                $block .= '        tags: ['.implode(', ', array_map(fn (string $tag): string => $this->yaml($tag), $instanceTags))."]\n";
            }
            $added[$id] = ['id' => $id, 'found_as' => $found, 'htdocs' => (string) $instance['htdocs'], 'tags' => $instanceTags];
        }
        $this->append($file, $block);
        Inventory::load($file);
        if ($this->isJson()) {
            $this->json(['server' => $server, 'file' => $file, 'added' => array_values($added)]);

            return self::EXIT_OK;
        }
        $this->output->writeln('<info>Added</info> '.count($selected).' instance(s) of '.$server.' to '.$file.'. Next: dolifleet check server:'.$server);

        return self::EXIT_OK;
    }

    /**
     * --rename=<found-id>=<new-id>[,...], syntax only: the identifiers are checked once the
     * instances are known.
     *
     * @return array<string, string> New identifier by found identifier
     */
    private function renames(): array
    {
        $renames = [];
        foreach (self::ids((string) $this->input->getOption('rename')) as $item) {
            $parts = array_map('trim', explode('=', $item));
            if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                throw new InventoryException("Invalid --rename item '{$item}': expected <found-id>=<new-id>.");
            }
            if (isset($renames[$parts[0]])) {
                throw new InventoryException("Instance '{$parts[0]}' is renamed twice in --rename.");
            }
            $renames[$parts[0]] = $parts[1];
        }

        return $renames;
    }

    /**
     * --tags, checked here so that the inventory is never written with a tag it would refuse.
     *
     * @return list<string>
     */
    private function tags(): array
    {
        $tags = self::ids((string) $this->input->getOption('tags'));
        foreach ($tags as $tag) {
            if (1 !== preg_match(Inventory::TAG_PATTERN, $tag)) {
                throw new InventoryException("Invalid tag '{$tag}' in --tags (expected [a-z0-9][a-z0-9_.-]*).");
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Identifier given to an added instance: valid, free in the inventory, not chosen twice.
     *
     * @param array<string, mixed> $added Instances already accepted, by identifier
     */
    private function checkNewId(string $id, string $found, ?Inventory $existing, array $added): void
    {
        $origin = $id === $found ? "Instance '{$id}'" : "New identifier '{$id}' of '{$found}'";
        $hint = $id === $found ? " Give it another identifier with --rename={$found}=<new-id>." : '';
        if (1 !== preg_match(Inventory::ID_PATTERN, $id) || in_array($id, Inventory::RESERVED, true)) {
            throw new InventoryException("{$origin} is not a valid identifier (expected [a-z0-9][a-z0-9_-]*, not ".implode(', ', Inventory::RESERVED).').'.$hint);
        }
        if (null !== $existing && isset($existing->instances()[$id])) {
            throw new InventoryException("{$origin} is already in the inventory.".$hint);
        }
        if (isset($added[$id])) {
            throw new InventoryException("{$origin} is also the identifier of '{$added[$id]['found_as']}' in this addition.");
        }
    }

    /**
     * @return list<string> Items of a comma separated option, trimmed, without empty ones
     */
    private static function ids(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => '' !== $item));
    }

    /**
     * Progress of the assistant: on the standard output, or on the error output with --json so
     * that the standard output stays one JSON document.
     */
    private function say(string $line): void
    {
        if ( ! $this->isJson()) {
            $this->output->writeln($line);

            return;
        }
        $stderr = method_exists($this->output, 'getErrorOutput') ? $this->output->getErrorOutput() : $this->output;
        $stderr->writeln($line);
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
        $this->say("Host key of {$server}:\n".self::clean(implode("\n", $fingerprints)));
        if ( ! $this->input->getOption('accept-host-key')) {
            $answer = $this->isJson() ? null : $this->ask('Is this the key of '.$server.'? Type yes to add it to known_hosts: ');
            if (null === $answer) {
                $this->error('Unknown host key of '.$server.': check its fingerprint, then accept it with dolifleet hostkey '.$server.' --accept, or add --accept-host-key.');

                return false;
            }
            if ('yes' !== trim($answer)) {
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

    /**
     * Mapping key: plain when YAML reads it back as the same string, quoted otherwise (true,
     * null, 1e3 and 0x1f are valid identifiers but not string keys).
     */
    private function key(string $key): string
    {
        try {
            $parsed = Yaml::parse($key.': x');
            if (is_array($parsed) && $key === array_key_first($parsed)) {
                return $key;
            }
        } catch (ParseException) {
            // Not a plain key: quoted below.
        }

        return $this->yaml($key);
    }
}
