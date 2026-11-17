<?php

declare(strict_types=1);

namespace Dolifleet\Remote;

/**
 * Outcome of a remote command.
 */
final class Result
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $seconds = 0.0,
    ) {
    }

    /**
     * ssh exits with 255 when the connection itself failed.
     */
    public function connectionFailed(): bool
    {
        return 255 === $this->exitCode;
    }
}
