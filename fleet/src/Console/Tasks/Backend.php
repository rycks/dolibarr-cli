<?php

declare(strict_types=1);

namespace Dolifleet\Console\Tasks;

/**
 * Where the console runs its dolifleet commands. Never blocks: start() returns at once,
 * poll() hands over the tasks finished since the previous call.
 */
interface Backend
{
    /**
     * @param list<string> $args Arguments of dolifleet without --json
     * @param list<string> $options Options added at run time (--confirm, --dry-run...), not shown
     * @param list<string> $instances
     * @param array<string, mixed> $meta
     */
    public function start(array $args, string $purpose, array $instances, bool $write, array $options = [], array $meta = []): Task;

    /**
     * @return list<Task>
     */
    public function poll(): array;

    /**
     * Every task of the session, oldest first, and the writes still running from earlier
     * sessions (they survive the console).
     *
     * @return list<Task>
     */
    public function all(): array;

    /**
     * Number of reads running now (the console keeps it under the parallel setting).
     */
    public function runningReads(): int;
}
