<?php

declare(strict_types=1);

namespace Dolifleet\Console\Tasks;

/**
 * One dolifleet command started by the console: the command line an administrator would type,
 * its state, and once finished its exit code, output and decoded JSON.
 */
final class Task
{
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public ?int $exitCode = null;

    public string $stdout = '';

    public string $stderr = '';

    public ?float $endedAt = null;

    /** @var array<string, mixed>|list<mixed>|null */
    public ?array $json = null;

    /**
     * @param list<string> $args Arguments of dolifleet, without --json (the command shown)
     * @param list<string> $instances Instances concerned
     * @param array<string, mixed> $meta Free data of the screen that started it
     */
    public function __construct(
        public readonly string $id,
        public readonly array $args,
        public readonly string $purpose,
        public readonly array $instances,
        public readonly bool $write,
        public readonly float $startedAt,
        public array $meta = [],
    ) {
    }

    public function state(): string
    {
        if (null === $this->exitCode) {
            return self::RUNNING;
        }

        return 0 === $this->exitCode ? self::DONE : self::FAILED;
    }

    public function finished(): bool
    {
        return null !== $this->exitCode;
    }

    public function duration(): float
    {
        return ($this->endedAt ?? microtime(true)) - $this->startedAt;
    }

    /**
     * The command as typed in a shell: dolifleet followed by the arguments, quoted when needed.
     */
    public function commandLine(): string
    {
        return Command::line($this->args);
    }

    /**
     * Outcome of one instance in the JSON of a pilot command ({instances: {id: {...}}}).
     *
     * @return array<string, mixed>
     */
    public function outcome(string $instance): array
    {
        $outcome = $this->json['instances'][$instance] ?? null;

        return is_array($outcome) ? $outcome : [];
    }

    /**
     * Data of the dolicli envelope of one instance.
     *
     * @return array<string, mixed>
     */
    public function data(string $instance): array
    {
        $data = $this->outcome($instance)['envelope']['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * First error of the task: the error of an instance, or the last line of the error output
     * of the pilot (usage error, refused confirmation).
     */
    public function error(): string
    {
        foreach ((array) ($this->json['instances'] ?? []) as $outcome) {
            if (is_array($outcome) && empty($outcome['ok']) && '' !== (string) ($outcome['error'] ?? '')) {
                return (string) $outcome['error'];
            }
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $this->stderr)), static fn (string $l): bool => '' !== $l));

        return [] === $lines ? '' : (string) end($lines);
    }

    /**
     * Last lines of the error output, cleaned (shown under an error, all of them in the backstage).
     *
     * @return list<string>
     */
    public function stderrTail(int $count): array
    {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $this->stderr)), static fn (string $l): bool => '' !== $l));

        return array_slice($lines, -$count);
    }
}
