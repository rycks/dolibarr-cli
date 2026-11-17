<?php

declare(strict_types=1);

namespace Dolifleet\Console;

use Dolifleet\Paths;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Preferences of the operator, not of the fleet (~/.config/dolifleet/settings.yaml):
 *
 *   lang: fr        language of the console
 *   console: true   whether dolifleet alone opens the console on a terminal
 *
 * Separate from the inventory, which a team may share.
 */
final class Settings
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private readonly string $file, private array $values)
    {
    }

    public static function path(): string
    {
        return Paths::configDir().'/settings.yaml';
    }

    public static function load(string $file = ''): self
    {
        $file = '' === $file ? self::path() : $file;
        if ( ! is_file($file)) {
            return new self($file, []);
        }
        try {
            $values = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new RuntimeException("Invalid settings file {$file}: ".$e->getMessage());
        }

        return new self($file, is_array($values) ? $values : []);
    }

    public function lang(): ?string
    {
        $lang = $this->values['lang'] ?? null;

        return is_string($lang) && '' !== $lang ? $lang : null;
    }

    public function console(): bool
    {
        return false !== ($this->values['console'] ?? true);
    }

    public function withLang(string $lang): self
    {
        $values = $this->values;
        $values['lang'] = $lang;

        return new self($this->file, $values);
    }

    public function save(): void
    {
        Paths::ensure(dirname($this->file));
        $tmp = $this->file.'.tmp';
        if (false === file_put_contents($tmp, Yaml::dump($this->values)) || ! rename($tmp, $this->file)) {
            throw new RuntimeException("Cannot write {$this->file}");
        }
        @chmod($this->file, 0600);
    }
}
