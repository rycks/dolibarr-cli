<?php

namespace Dolicli\System;

/**
 * External processes, from PHP 7.0.
 *
 * proc_open() takes an argument array (no shell) only from PHP 7.4: before, the
 * command is a string run by /bin/sh, so every argument is escaped. Secrets never
 * go in arguments (visible in ps): use the environment or a private file.
 */
final class Process
{
    /** @var int */
    private $exitCode = -1;

    /** @var string */
    private $stdout = '';

    /** @var string */
    private $stderr = '';

    /** @var string */
    private $error = '';

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env Variables added to the current environment
     * @param string|null $stdoutFile Write stdout in this file instead of capturing it
     * @param string|null $cwd
     * @param callable|null $onOutput Receives stdout chunks as they arrive (when not written to a file)
     */
    public function run(array $command, array $env = [], $stdoutFile = null, $cwd = null, $onOutput = null): int
    {
        $this->exitCode = -1;
        $this->stdout = '';
        $this->stderr = '';
        $this->error = '';

        $environment = null;
        if ([] !== $env) {
            $environment = self::environment();
            foreach ($env as $name => $value) {
                $environment[$name] = $value;
            }
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => null === $stdoutFile ? ['pipe', 'w'] : ['file', $stdoutFile, 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(self::commandLine($command), $descriptors, $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            $this->error = 'Cannot start ' . $command[0];
            Log::error($this->error);

            return -1;
        }

        $streams = [];
        if (null === $stdoutFile) {
            stream_set_blocking($pipes[1], false);
            $streams[1] = $pipes[1];
        }
        stream_set_blocking($pipes[2], false);
        $streams[2] = $pipes[2];

        while ([] !== $streams) {
            $read = array_values($streams);
            $write = null;
            $except = null;
            if (false === @stream_select($read, $write, $except, 1)) {
                break;
            }
            foreach ($streams as $index => $stream) {
                $chunk = (string) fread($stream, 65536);
                if ('' !== $chunk) {
                    if (1 === $index) {
                        $this->stdout .= $chunk;
                        if (null !== $onOutput) {
                            call_user_func($onOutput, $chunk);
                        }
                    } else {
                        $this->stderr .= $chunk;
                    }
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($streams[$index]);
                }
            }
        }

        $status = proc_get_status($process);
        $code = proc_close($process);
        // proc_close() returns -1 when proc_get_status() already collected the exit code.
        $this->exitCode = (!$status['running'] && $status['exitcode'] >= 0) ? (int) $status['exitcode'] : $code;
        if (0 !== $this->exitCode) {
            Log::error(basename($command[0]) . ' exited with code ' . $this->exitCode . ': ' . trim(substr($this->stderr, 0, 1000)));
        }

        return $this->exitCode;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    public function error(): string
    {
        return '' !== $this->error ? $this->error : trim($this->stderr);
    }

    /**
     * Current environment: getenv() without argument exists from PHP 7.1 only,
     * /proc/self/environ is read before.
     *
     * @return array<string, string>
     */
    public static function environment(): array
    {
        if (version_compare(PHP_VERSION, '7.1.0', '>=')) {
            $all = call_user_func('getenv');

            return is_array($all) ? $all : [];
        }
        $environment = [];
        $raw = @file_get_contents('/proc/self/environ');
        if (is_string($raw)) {
            foreach (explode("\0", $raw) as $entry) {
                $position = strpos($entry, '=');
                if (false !== $position && $position > 0) {
                    $environment[substr($entry, 0, $position)] = substr($entry, $position + 1);
                }
            }
        }

        return $environment;
    }

    /**
     * Argument array from PHP 7.4, escaped string before.
     *
     * @param array<int, string> $command
     * @return array<int, string>|string
     */
    public static function commandLine(array $command)
    {
        if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
            return $command;
        }

        return 'exec ' . implode(' ', array_map('escapeshellarg', $command));
    }

    /**
     * Absolute path of a program found in PATH (or in the usual sbin directories), empty when absent.
     *
     * @param array<int, string> $names
     */
    public static function find(array $names): string
    {
        $path = getenv('PATH');
        $dirs = array_merge(explode(PATH_SEPARATOR, is_string($path) ? $path : ''), ['/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin']);
        foreach ($names as $name) {
            foreach ($dirs as $dir) {
                if ('' !== $dir && is_file($dir . '/' . $name) && is_executable($dir . '/' . $name)) {
                    return $dir . '/' . $name;
                }
            }
        }

        return '';
    }
}
