<?php

declare(strict_types=1);

namespace Dolifleet\Console;

/**
 * A write started from the console and followed until its end: maintenance, backup (jobs on
 * the servers), upgrade (steps of the plan), or any write of the palette.
 */
final class Operation
{
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const PAUSED = 'paused';

    public string $state = self::RUNNING;

    /** @var list<array{state: string, label: string, detail: string}> */
    public array $steps = [];

    public string $error = '';

    public ?float $endedAt = null;

    /** @var array<string, string> instance => job id on the server (backups) */
    public array $jobs = [];

    /** @var array<string, string> instance => state of its job */
    public array $jobStates = [];

    public float $nextPoll = 0.0;

    public bool $polling = false;

    /** Step of a paused upgrade waiting for the administrator. */
    public string $pausedStep = '';

    /** @var list<string> Ids of the tasks of this operation */
    public array $tasks = [];

    /**
     * @param list<string> $instances
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $title,
        public readonly array $instances,
        public readonly float $startedAt,
        public array $meta = [],
    ) {
    }

    public function finished(): bool
    {
        return in_array($this->state, [self::DONE, self::FAILED], true);
    }

    /**
     * Steps done out of the total, for the band: "3/7".
     */
    public function progress(): string
    {
        if ([] === $this->steps) {
            return '';
        }
        $done = count(array_filter($this->steps, static fn (array $s): bool => 'done' === $s['state']));

        return $done.'/'.count($this->steps);
    }
}
