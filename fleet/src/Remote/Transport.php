<?php

declare(strict_types=1);

namespace Dolifleet\Remote;

/**
 * Way to run a command on a server. The command is a list of words, escaped for a POSIX shell
 * by the transport; the standard input carries the body and the arguments of dolicli.
 */
interface Transport
{
    /**
     * @param list<string> $command
     */
    public function run(string $server, array $command, string $stdin = '', int $timeout = 600): Result;

    /**
     * Several commands at once, at most $parallel at the same time. Keys are kept.
     *
     * @param array<string, array{server: string, command: list<string>, stdin: string}> $jobs
     * @return array<string, Result>
     */
    public function runMany(array $jobs, int $parallel, int $timeout = 600): array;

    /**
     * SSH account used for a server (ssh -G), to know whether sudo is needed.
     */
    public function remoteUser(string $server): string;
}
