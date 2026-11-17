<?php

namespace Dolicli\Cli;

use Dolicli\Dolibarr\Descriptors;
use Dolicli\System\Log;
use Dolicli\System\Paths;
use Dolicli\System\Process;
use Dolicli\Version;

/**
 * Run dolicli again in a child process (same PHP binary, same arguments, same account, same
 * directory) with module descriptors left out, given in DOLICLI_SKIP_DESCRIPTORS: module list
 * answers that way when a descriptor stopped PHP while it was loaded. The child writes the answer
 * in place of this process.
 *
 * Installed mode: the main script (launcher, or body run directly) is run again. Ephemeral mode:
 * the body came on the standard input of PHP, already consumed, so the child receives it again
 * on its own standard input, from a copy of the body held by this process: $DOLICLI_SOURCE (the
 * stream of Stream::build), else the body sent along in $DOLICLI_FILES, else an installed body
 * with the same SHA256.
 */
final class Relaunch
{
    /** @var array<int, string> */
    private $command = [];

    /** @var string|null */
    private $stdin;

    /** @var string Why the relaunch is impossible, empty when it is possible */
    private $unavailable = '';

    /**
     * @param array<int, mixed> $argv Arguments of this run, program name first
     * @param bool $fromStream True in the ephemeral mode
     * @param string $format Output format chosen by this run, given explicitly to the child
     */
    public function __construct(array $argv, bool $fromStream, string $format)
    {
        $arguments = [];
        foreach (array_slice($argv, 1) as $argument) {
            $arguments[] = (string) $argument;
        }
        // Without --format, the format depends on stdout: a pipe for the child.
        $arguments[] = '--format=' . $format;
        if (!is_executable(PHP_BINARY)) {
            $this->unavailable = 'the PHP binary of this run (' . PHP_BINARY . ') is not executable';

            return;
        }

        if (!$fromStream) {
            $included = get_included_files();
            $main = isset($included[0]) ? $included[0] : '';
            if ('' === $main || !is_file($main)) {
                $this->unavailable = 'the main script of this run is not a readable file';

                return;
            }
            $this->command = array_merge([PHP_BINARY, $main], $arguments);

            return;
        }

        $source = self::bodyCopy();
        if ('' === $source) {
            $this->unavailable = 'the body came on stdin without a copy of its text, and no identical dolicli is installed';

            return;
        }
        try {
            $this->stdin = Stream::build(array_merge([isset($argv[0]) ? (string) $argv[0] : 'dolicli'], $arguments), $source);
        } catch (Failure $failure) {
            $this->unavailable = $failure->getMessage();

            return;
        }
        $this->command = [PHP_BINARY, '--'];
    }

    public function possible(): bool
    {
        return '' === $this->unavailable;
    }

    /**
     * Why the relaunch is impossible, empty when it is possible.
     */
    public function unavailable(): string
    {
        return $this->unavailable;
    }

    /**
     * Run the child, its stdout and stderr passed through. Returns its exit code, null when it
     * could not start (the caller then reports its own error).
     *
     * @param array<int, array{class: string, file: string, reason: string}> $skipped
     * @return int|null
     */
    public function run(array $skipped, string $cwd, Console $console)
    {
        if (!$this->possible()) {
            Log::error('cannot relaunch dolicli: ' . $this->unavailable);

            return null;
        }
        $process = new Process();
        $env = [Descriptors::SKIP_VARIABLE => Json::encode($skipped, false)];
        $onOutput = function ($chunk) use ($console) {
            $console->out((string) $chunk);
        };
        $code = $process->run($this->command, $env, null, '' === $cwd ? null : $cwd, $onOutput, $this->stdin);
        if (-1 === $code && '' === $process->stdout()) {
            Log::error('cannot relaunch dolicli: ' . $process->error());

            return null;
        }
        if ('' !== $process->stderr()) {
            $console->err($process->stderr());
        }

        return $code;
    }

    /**
     * Text of the running body, when it came on stdin: empty when no copy is available.
     */
    private static function bodyCopy(): string
    {
        if (isset($GLOBALS['DOLICLI_SOURCE']) && is_string($GLOBALS['DOLICLI_SOURCE']) && '' !== $GLOBALS['DOLICLI_SOURCE']) {
            return $GLOBALS['DOLICLI_SOURCE'];
        }
        if (isset($GLOBALS['DOLICLI_FILES']['body']) && is_string($GLOBALS['DOLICLI_FILES']['body'])) {
            $body = base64_decode($GLOBALS['DOLICLI_FILES']['body'], true);
            if (is_string($body) && self::isThisBody($body)) {
                return $body;
            }
        }
        $installed = Paths::installedBody();
        if (is_file($installed)) {
            $body = @file_get_contents($installed);
            if (is_string($body) && self::isThisBody($body)) {
                return $body;
            }
            Log::info("relaunch: {$installed} is not the body of this run");
        }

        return '';
    }

    /**
     * Same build as the running body: same SHA256 injected by the build.
     */
    private static function isThisBody(string $body): bool
    {
        $hash = Version::BODY_SHA256;

        return 1 === preg_match('/^[0-9a-f]{64}$/', $hash) && false !== strpos($body, "const BODY_SHA256 = '" . $hash . "';");
    }
}
