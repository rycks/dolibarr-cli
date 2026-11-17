<?php

namespace Dolicli\Cli;

/**
 * Command line: `dolicli <group> <command> [key=value ...] [--option ...]`.
 *
 * Parameters are accepted as `key=value` and `--key=value`; a bare `--flag`
 * is a flag. Everything else is a positional word.
 */
final class Args
{
    /** @var array<int, string> */
    private $words = [];

    /** @var array<string, string> */
    private $values = [];

    /** @var array<string, bool> */
    private $flags = [];

    /**
     * Names given with a value through the real command line ($argv), as opposed to
     * the stream of the ephemeral mode: secrets are refused there.
     *
     * @var array<string, bool>
     */
    private $fromCommandLine = [];

    /** @var array<int, string> */
    private $invalid = [];

    /**
     * @param array<int, mixed> $arguments Arguments without the program name
     */
    public function __construct(array $arguments, bool $fromCommandLine = true)
    {
        foreach ($arguments as $argument) {
            $argument = (string) $argument;
            if ('' === $argument) {
                continue;
            }
            if (1 === preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/s', $argument, $m)) {
                $this->setValue($m[1], $m[2], $fromCommandLine);
            } elseif (1 === preg_match('/^--([a-z][a-z0-9-]*)$/', $argument, $m)) {
                $this->flags[$m[1]] = true;
            } elseif ('-h' === $argument) {
                $this->flags['help'] = true;
            } elseif (1 === preg_match('/^([a-z][a-z0-9_-]*)=(.*)$/s', $argument, $m)) {
                $this->setValue($m[1], $m[2], $fromCommandLine);
            } elseif ('-' === $argument[0]) {
                $this->invalid[] = $argument;
            } else {
                $this->words[] = $argument;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function words(): array
    {
        return $this->words;
    }

    /**
     * Drop the first $count words (the command name), keep the rest as positional arguments.
     */
    public function shiftWords(int $count): self
    {
        $copy = clone $this;
        $copy->words = array_slice($this->words, $count);

        return $copy;
    }

    /**
     * @return string|null
     */
    public function value(string $name)
    {
        return isset($this->values[$name]) ? $this->values[$name] : null;
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]) || isset($this->flags[$name]);
    }

    public function flag(string $name): bool
    {
        if (isset($this->flags[$name])) {
            return true;
        }
        if (isset($this->values[$name])) {
            return in_array(strtolower($this->values[$name]), ['1', 'yes', 'true', 'on'], true);
        }

        return false;
    }

    public function givenOnCommandLine(string $name): bool
    {
        return isset($this->fromCommandLine[$name]);
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_merge(array_keys($this->values), array_keys($this->flags))));
    }

    /**
     * @return array<string, bool>
     */
    public function flagNames(): array
    {
        return $this->flags;
    }

    /**
     * @return array<int, string>
     */
    public function invalid(): array
    {
        return $this->invalid;
    }

    /**
     * Arguments as given (for the journal), sensitive values masked.
     *
     * @param array<int, string> $sensitive
     * @return array<int, string>
     */
    public function describe(array $sensitive): array
    {
        $parts = $this->words;
        foreach ($this->values as $name => $value) {
            $parts[] = $name . '=' . (in_array($name, $sensitive, true) ? '***' : $value);
        }
        foreach (array_keys($this->flags) as $name) {
            $parts[] = '--' . $name;
        }

        return $parts;
    }

    private function setValue(string $name, string $value, bool $fromCommandLine)
    {
        $name = str_replace('_', '-', $name);
        $this->values[$name] = $value;
        if ($fromCommandLine) {
            $this->fromCommandLine[$name] = true;
        }
    }
}
