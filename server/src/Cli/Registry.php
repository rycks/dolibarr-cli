<?php

namespace Dolicli\Cli;

/**
 * List of the commands. Explicit on purpose: the body is a single file, nothing
 * is discovered on disk.
 */
final class Registry
{
    /** @var array<string, Command> */
    private $commands = [];

    /**
     * @param array<int, Command> $commands
     */
    public function __construct(array $commands)
    {
        foreach ($commands as $command) {
            $this->commands[$command->name()] = $command;
        }
        ksort($this->commands);
    }

    public static function standard(): self
    {
        $commands = [];
        foreach (self::commandClasses() as $class) {
            $commands[] = new $class();
        }
        $registry = new self($commands);
        foreach ($registry->commands as $command) {
            if ($command instanceof RegistryAware) {
                $command->setRegistry($registry);
            }
        }

        return $registry;
    }

    /**
     * @return array<int, class-string<Command>>
     */
    public static function commandClasses(): array
    {
        return [
            \Dolicli\Command\HelpCommand::class,
            \Dolicli\Command\VersionCommand::class,
            \Dolicli\Command\Env\DoctorCommand::class,
        ];
    }

    /**
     * @return array<string, Command>
     */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * Longest command matching the first words.
     *
     * @param array<int, string> $words
     * @return array{0: Command, 1: int}|null Command and number of words used
     */
    public function find(array $words)
    {
        for ($length = min(3, count($words)); $length >= 1; $length--) {
            $name = implode(' ', array_slice($words, 0, $length));
            if (isset($this->commands[$name])) {
                return [$this->commands[$name], $length];
            }
        }

        return null;
    }

    /**
     * Commands whose name starts with the given words ('user' -> user add, user list...).
     *
     * @param array<int, string> $words
     * @return array<string, Command>
     */
    public function withPrefix(array $words): array
    {
        $prefix = implode(' ', $words) . ' ';
        $found = [];
        foreach ($this->commands as $name => $command) {
            if (0 === strpos($name . ' ', $prefix)) {
                $found[$name] = $command;
            }
        }

        return $found;
    }
}
