<?php

namespace Dolicli\Instance;

use Dolicli\Cli\Failure;
use Dolicli\System\Log;
use Dolicli\System\Paths;
use Dolicli\System\Posix;

/**
 * /etc/dolicli/instances.ini: the instances of the server, reference on the server side.
 *
 *   [client-a-prod]
 *   htdocs = /var/www/client-a/htdocs
 *   run_as = www-data
 *   php = /usr/bin/php7.4
 */
final class InstancesIni
{
    const KEYS = [
        'htdocs', 'run_as', 'php', 'web_php', 'dolibarr_user', 'maintenance_login',
        'backup_dir', 'backup_pattern', 'prom_dir', 'monitor_group',
    ];

    /** @var array<string, Instance> */
    private $instances = [];

    /** @var array<int, string> */
    private $errors = [];

    /** @var string */
    private $path;

    private function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function load(string $path = ''): self
    {
        $ini = new self('' === $path ? Paths::instancesIni() : $path);
        if (is_file($ini->path)) {
            $ini->parse();
        }

        return $ini;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return array<string, Instance>
     */
    public function all(): array
    {
        return $this->instances;
    }

    /**
     * @return Instance|null
     */
    public function get(string $id)
    {
        return isset($this->instances[$id]) ? $this->instances[$id] : null;
    }

    /**
     * Problems found while reading the file (invalid identifiers, unknown keys...).
     *
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, Instance> $instances
     */
    public static function render(array $instances): string
    {
        $out = "; Instances managed by dolicli. Written by dolicli setup or dolifleet setup.\n";
        foreach ($instances as $instance) {
            $out .= "\n[" . $instance->id() . "]\n";
            $out .= 'htdocs = ' . self::quote($instance->htdocs()) . "\n";
            foreach (self::KEYS as $key) {
                $value = $instance->setting($key);
                if ('htdocs' !== $key && '' !== $value) {
                    $out .= $key . ' = ' . self::quote($value) . "\n";
                }
            }
        }

        return $out;
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace('"', '', $value) . '"';
    }

    private function parse()
    {
        $data = @parse_ini_file($this->path, true, INI_SCANNER_RAW);
        if (!is_array($data)) {
            $error = error_get_last();
            $this->errors[] = 'Cannot parse ' . $this->path . (null !== $error ? ': ' . $error['message'] : '');
            Log::error(end($this->errors));
            throw Failure::environment('INSTANCES_INI_INVALID', end($this->errors) . '.');
        }

        foreach ($data as $id => $section) {
            $id = (string) $id;
            if (!is_array($section)) {
                $this->errors[] = "Value outside a section: {$id}";
                continue;
            }
            if (!Instance::isValidId($id)) {
                $this->errors[] = "Invalid instance identifier '{$id}' (expected [a-z0-9][a-z0-9_-]*, not 'all')";
                continue;
            }
            $settings = [];
            foreach ($section as $key => $value) {
                $key = (string) $key;
                if (!in_array($key, self::KEYS, true)) {
                    $this->errors[] = "Unknown key '{$key}' in [{$id}]";
                    continue;
                }
                $settings[$key] = trim((string) $value, " \t\"'");
            }
            if (!isset($settings['htdocs']) || '' === $settings['htdocs'] || '/' !== $settings['htdocs'][0]) {
                $this->errors[] = "[{$id}]: htdocs must be an absolute path";
                continue;
            }
            if (isset($settings['run_as']) && !Posix::isValidAccountName($settings['run_as'])) {
                $this->errors[] = "[{$id}]: invalid run_as '{$settings['run_as']}'";
                continue;
            }
            foreach (['php', 'backup_dir', 'prom_dir'] as $pathKey) {
                if (isset($settings[$pathKey]) && '' !== $settings[$pathKey] && '/' !== $settings[$pathKey][0]) {
                    $this->errors[] = "[{$id}]: {$pathKey} must be an absolute path";
                    continue 2;
                }
            }
            $this->instances[$id] = new Instance($id, $settings['htdocs'], 'instances.ini', $settings);
        }
        foreach ($this->errors as $error) {
            Log::warning('instances.ini: ' . $error);
        }
    }
}
