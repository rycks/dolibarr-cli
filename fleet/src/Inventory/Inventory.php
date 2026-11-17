<?php

declare(strict_types=1);

namespace Dolifleet\Inventory;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Inventory of the pilot (~/.config/dolifleet/inventory.yaml and its includes).
 *
 * No host, port, SSH user nor secret: a server is an alias of ~/.ssh/config. Included files
 * often come from a shared repository, so every value is validated as untrusted data, and an
 * instance or server defined twice is an error, never a silent override.
 */
final class Inventory
{
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';
    public const SERVER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';
    public const PATH_PATTERN = '#^/[A-Za-z0-9._/+@-]*$#';
    public const ACCOUNT_PATTERN = '/^[a-z_][a-z0-9_.-]{0,31}$/';
    public const LOGIN_PATTERN = '/^[A-Za-z0-9._@-]{1,64}$/';
    public const TAG_PATTERN = '/^[a-z0-9][a-z0-9_.-]*$/';
    public const RESERVED = ['all'];

    /** @var array<string, Instance> */
    private array $instances = [];

    /** @var array<string, array{php: string, mode: string, source: string}> */
    private array $servers = [];

    /** @var array<string, mixed> */
    private array $defaults = [
        'parallel' => 4,
        'ssh_timeout' => 10,
        'protected_tags' => ['prod'],
        'fast_interval' => 300,
    ];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $files = [];

    /**
     * No inventory yet (dolifleet add on a new workstation).
     */
    public static function empty(): self
    {
        return new self();
    }

    public static function load(string $file): self
    {
        $inventory = new self();
        if ( ! is_file($file)) {
            throw new InventoryException("Inventory not found: {$file} (dolifleet add creates it).");
        }
        $inventory->loadFile($file, true);
        if ([] !== $inventory->errors) {
            throw new InventoryException("Invalid inventory:\n  ".implode("\n  ", $inventory->errors));
        }

        return $inventory;
    }

    /**
     * @return array<string, Instance>
     */
    public function instances(): array
    {
        return $this->instances;
    }

    public function instance(string $id): Instance
    {
        if ( ! isset($this->instances[$id])) {
            throw new InventoryException("Unknown instance '{$id}'.");
        }

        return $this->instances[$id];
    }

    /**
     * @return array<string, array{php: string, mode: string, source: string}>
     */
    public function servers(): array
    {
        return $this->servers;
    }

    public function serverMode(string $server): string
    {
        return $this->servers[$server]['mode'] ?? 'ephemeral';
    }

    public function default(string $name): mixed
    {
        return $this->defaults[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function protectedTags(): array
    {
        return array_values(array_map('strval', (array) $this->defaults['protected_tags']));
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        return $this->files;
    }

    private function loadFile(string $file, bool $main): void
    {
        $real = realpath($file);
        if (false === $real) {
            $this->errors[] = "{$file}: not found";

            return;
        }
        if (in_array($real, $this->files, true)) {
            $this->errors[] = "{$file}: included twice";

            return;
        }
        $this->files[] = $real;
        try {
            $data = Yaml::parseFile($real);
        } catch (ParseException $e) {
            $this->errors[] = "{$real}: ".$e->getMessage();

            return;
        }
        if (null === $data) {
            return;
        }
        if ( ! is_array($data)) {
            $this->errors[] = "{$real}: the root must be a mapping";

            return;
        }
        foreach (array_keys($data) as $key) {
            if ( ! in_array($key, ['include', 'defaults', 'servers'], true)) {
                $this->error($real, (string) $key, "unknown key '{$key}'");
            }
        }

        if (isset($data['defaults'])) {
            if ( ! $main) {
                $this->error($real, 'defaults', 'defaults are only allowed in the main inventory');
            } elseif (is_array($data['defaults'])) {
                $this->readDefaults($real, $data['defaults']);
            }
        }
        foreach ((array) ($data['servers'] ?? []) as $alias => $server) {
            $this->readServer($real, (string) $alias, is_array($server) ? $server : []);
        }
        foreach ((array) ($data['include'] ?? []) as $pattern) {
            $pattern = (string) $pattern;
            if (str_starts_with($pattern, '~/')) {
                $pattern = getenv('HOME').mb_substr($pattern, 1);
            } elseif ( ! str_starts_with($pattern, '/')) {
                $pattern = dirname($real).'/'.$pattern;
            }
            $matches = glob($pattern) ?: [];
            if ([] === $matches) {
                $this->error($real, 'include', "include matches no file: {$pattern}");
            }
            sort($matches);
            foreach ($matches as $included) {
                $this->loadFile($included, false);
            }
        }
    }

    /**
     * @param array<mixed> $defaults
     */
    private function readDefaults(string $file, array $defaults): void
    {
        foreach ($defaults as $key => $value) {
            switch ($key) {
                case 'parallel':
                case 'ssh_timeout':
                case 'fast_interval':
                    if ( ! is_int($value) || $value < 1) {
                        $this->error($file, (string) $key, "{$key} must be a positive integer");
                        break;
                    }
                    $this->defaults[$key] = $value;
                    break;
                case 'protected_tags':
                    $tags = $this->tags($file, (string) $key, $value);
                    $this->defaults[$key] = $tags;
                    break;
                default:
                    $this->error($file, (string) $key, "unknown default '{$key}'");
            }
        }
    }

    /**
     * @param array<mixed> $server
     */
    private function readServer(string $file, string $alias, array $server): void
    {
        if (1 !== preg_match(self::SERVER_PATTERN, $alias) || in_array($alias, self::RESERVED, true)) {
            $this->error($file, $alias, "invalid server alias '{$alias}' (letters, digits, . _ -, no target characters)");

            return;
        }
        if (isset($this->servers[$alias])) {
            $this->error($file, $alias, "server '{$alias}' already defined in {$this->servers[$alias]['source']}");

            return;
        }
        foreach (array_keys($server) as $key) {
            if ( ! in_array($key, ['php', 'mode', 'instances'], true)) {
                $this->error($file, (string) $key, "unknown key '{$key}' for server {$alias}");
            }
        }
        $php = $this->path($file, 'php', $server['php'] ?? '');
        $mode = (string) ($server['mode'] ?? 'ephemeral');
        if ( ! in_array($mode, ['ephemeral', 'installed'], true)) {
            $this->error($file, 'mode', "mode of {$alias} must be ephemeral or installed");
            $mode = 'ephemeral';
        }
        $this->servers[$alias] = ['php' => $php, 'mode' => $mode, 'source' => $file];

        foreach ((array) ($server['instances'] ?? []) as $id => $instance) {
            $this->readInstance($file, $alias, (string) $id, is_array($instance) ? $instance : [], $php);
        }
    }

    /**
     * @param array<mixed> $values
     */
    private function readInstance(string $file, string $server, string $id, array $values, string $serverPhp): void
    {
        if (1 !== preg_match(self::ID_PATTERN, $id) || in_array($id, self::RESERVED, true)) {
            $this->error($file, $id, "invalid instance identifier '{$id}' (expected [a-z0-9][a-z0-9_-]*, not 'all')");

            return;
        }
        if (isset($this->instances[$id])) {
            $this->error($file, $id, "instance '{$id}' already defined in {$this->instances[$id]->source}");

            return;
        }
        foreach (array_keys($values) as $key) {
            if ( ! in_array($key, ['htdocs', 'run_as', 'php', 'web_php', 'dolibarr_user', 'maintenance_login', 'tags'], true)) {
                $this->error($file, (string) $key, "unknown key '{$key}' for instance {$id}");
            }
        }
        $runAs = (string) ($values['run_as'] ?? '');
        if ('' !== $runAs && 1 !== preg_match(self::ACCOUNT_PATTERN, $runAs)) {
            $this->error($file, 'run_as', "instance {$id}: invalid run_as '{$runAs}'");
            $runAs = '';
        }
        $htdocs = $this->path($file, 'htdocs', $values['htdocs'] ?? '');
        if ('' === $htdocs) {
            if ( ! isset($values['htdocs'])) {
                $this->error($file, $id, "instance {$id}: htdocs is required");
            }

            return;
        }
        $webPhp = (string) ($values['web_php'] ?? '');
        if ('' !== $webPhp && 1 !== preg_match('/^\d+\.\d+$/', $webPhp)) {
            $this->error($file, 'web_php', "instance {$id}: web_php must be x.y");
            $webPhp = '';
        }
        $logins = [];
        foreach (['dolibarr_user', 'maintenance_login'] as $key) {
            $logins[$key] = (string) ($values[$key] ?? '');
            if ('' !== $logins[$key] && 1 !== preg_match(self::LOGIN_PATTERN, $logins[$key])) {
                $this->error($file, $key, "instance {$id}: invalid {$key}");
                $logins[$key] = '';
            }
        }
        $php = $this->path($file, 'php', $values['php'] ?? '');

        $this->instances[$id] = new Instance(
            $id,
            $server,
            $htdocs,
            $runAs,
            '' !== $php ? $php : $serverPhp,
            $webPhp,
            $logins['dolibarr_user'],
            $logins['maintenance_login'],
            $this->tags($file, 'tags', $values['tags'] ?? []),
            $file
        );
    }

    private function path(string $file, string $key, mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if ('' !== $value && 1 !== preg_match(self::PATH_PATTERN, $value)) {
            $this->error($file, $key, "{$key} must be an absolute path without shell characters: '{$value}'");

            return '';
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function tags(string $file, string $key, mixed $value): array
    {
        $tags = [];
        foreach ((array) $value as $tag) {
            $tag = is_scalar($tag) ? (string) $tag : '';
            if (1 !== preg_match(self::TAG_PATTERN, $tag)) {
                $this->error($file, $key, "invalid tag '{$tag}'");
                continue;
            }
            $tags[] = $tag;
        }

        return $tags;
    }

    private function error(string $file, string $key, string $message): void
    {
        $this->errors[] = $file.':'.self::lineOf($file, $key).': '.$message;
    }

    /**
     * Line where a key appears first (the YAML parser gives no line for valid documents).
     */
    private static function lineOf(string $file, string $key): int
    {
        $lines = file($file) ?: [];
        foreach ($lines as $number => $line) {
            if (1 === preg_match('/^\s*-?\s*[\'"]?'.preg_quote($key, '/').'[\'"]?\s*:/', $line)) {
                return $number + 1;
            }
        }

        return 0;
    }
}
