<?php

declare(strict_types=1);

namespace Dolifleet\Remote;

use Symfony\Component\Process\Process;

/**
 * The ssh binary of the system: ~/.ssh/config, ssh-agent, ProxyJump and multiplexing come for free.
 *
 * BatchMode=yes: never a blocking question (an unknown host key makes the connection fail).
 * The remote command is one string built here, every word escaped for a POSIX shell; only
 * validated values (inventory) go in it, the arguments of dolicli travel in the stream.
 */
final class SshTransport implements Transport
{
    /** @var array<string, array<string, string>> */
    private array $config = [];

    public function __construct(private readonly int $connectTimeout = 10, private readonly string $ssh = '')
    {
    }

    public function sshBinary(): string
    {
        if ('' !== $this->ssh) {
            return $this->ssh;
        }
        $env = getenv('DOLIFLEET_SSH');

        return is_string($env) && '' !== $env ? $env : 'ssh';
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    public function commandLine(string $server, array $command): array
    {
        $options = ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout='.$this->connectTimeout, '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3'];
        $controlMaster = $this->config($server)['controlmaster'] ?? '';
        if ('' === $controlMaster || 'false' === $controlMaster) {
            $dir = sys_get_temp_dir().'/dolifleet-'.(function_exists('posix_geteuid') ? posix_geteuid() : 'user');
            if ( ! is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            // Short ControlPath: Unix sockets are limited to about 100 characters.
            $options = array_merge($options, ['-o', 'ControlMaster=auto', '-o', 'ControlPersist=60', '-o', 'ControlPath='.$dir.'/%C']);
        }

        return array_merge([$this->sshBinary()], $options, [$server, '--', self::shellCommand($command)]);
    }

    /**
     * @param list<string> $words
     */
    public static function shellCommand(array $words): string
    {
        return implode(' ', array_map('escapeshellarg', $words));
    }

    public function run(string $server, array $command, string $stdin = '', int $timeout = 600): Result
    {
        $process = new Process($this->commandLine($server, $command), null, null, $stdin, $timeout);
        $start = microtime(true);
        $process->run();

        return new Result((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), microtime(true) - $start);
    }

    public function runMany(array $jobs, int $parallel, int $timeout = 600): array
    {
        $results = [];
        $queue = $jobs;
        /** @var array<string, array{0: Process, 1: float}> $running */
        $running = [];
        while ([] !== $queue || [] !== $running) {
            while ([] !== $queue && count($running) < max(1, $parallel)) {
                $key = (string) array_key_first($queue);
                $job = $queue[$key];
                unset($queue[$key]);
                $process = new Process($this->commandLine($job['server'], $job['command']), null, null, $job['stdin'], $timeout);
                $process->start();
                $running[$key] = [$process, microtime(true)];
            }
            foreach ($running as $key => [$process, $start]) {
                if ( ! $process->isRunning()) {
                    $results[$key] = new Result((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), microtime(true) - $start);
                    unset($running[$key]);
                }
            }
            usleep(20000);
        }
        $ordered = [];
        foreach (array_keys($jobs) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    public function remoteUser(string $server): string
    {
        return $this->config($server)['user'] ?? '';
    }

    /**
     * Effective configuration of an alias (ssh -G), cached.
     *
     * @return array<string, string>
     */
    private function config(string $server): array
    {
        if (isset($this->config[$server])) {
            return $this->config[$server];
        }
        $process = new Process([$this->sshBinary(), '-G', $server], null, null, null, 10);
        $process->run();
        $config = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            $parts = explode(' ', trim($line), 2);
            if (2 === count($parts)) {
                $config[mb_strtolower($parts[0])] = $parts[1];
            }
        }

        return $this->config[$server] = $config;
    }
}
