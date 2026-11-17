<?php

declare(strict_types=1);

namespace App\Util;

use Closure;

/**
 * Run a Dolibarr install/ script (upgrade.php, upgrade2.php, step5.php, repair.php)
 * in a separate PHP process.
 *
 * Including them in the dolicli process does not work: they bootstrap through
 * install/inc.php, read their parameters from $argv, and call exit(), which
 * would skip our cleanup (lock file, maintenance mode, ownership).
 */
final class InstallScriptRunner
{
    private string $stderr = '';

    /**
     * @param  Closure(string): void  $output  Receives the script output, HTML stripped, line by line
     */
    public function __construct(private readonly Closure $output)
    {
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    /**
     * Run install/$scriptName with $args (and optionally $_GET-style $query for web-only scripts).
     *
     * @param  array<int, string>  $args
     * @param  array<string, string>  $query  Parameters given in $_GET, for scripts reading GETPOST()
     * @param  array<int, string>  $defines  Constants defined before the script runs
     * @return int Process exit code (-1 if it could not be started)
     */
    public function run(string $installDir, string $scriptName, array $args = [], array $query = [], array $defines = []): int
    {
        $this->stderr = '';
        $scriptPath = rtrim($installDir, '/').'/'.$scriptName;
        if ( ! is_file($scriptPath)) {
            $this->stderr = "Install script not found: {$scriptPath}";
            DolibarrHelper::log($this->stderr, LOG_ERR);

            return -1;
        }

        if ([] === $query && [] === $defines) {
            $cmd = array_merge([PHP_BINARY, $scriptPath], $args);
        } else {
            // GETPOST() reads $_GET: fill it (and the constants) from a small wrapper, then run the script.
            $wrapper = '$_GET = '.var_export($query, true).';';
            foreach ($defines as $constant) {
                $wrapper .= ' define('.var_export($constant, true).', 1);';
            }
            $wrapper .= ' $argv = array_merge(['.var_export($scriptPath, true).'], array_slice($argv, 1)); $argc = count($argv);'
                .' require '.var_export($scriptPath, true).';';
            $cmd = array_merge([PHP_BINARY, '-r', $wrapper, '--'], $args);
        }

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, dirname($scriptPath));
        if ( ! is_resource($process)) {
            $this->stderr = "Cannot start a PHP process for {$scriptName}";
            DolibarrHelper::log($this->stderr, LOG_ERR);

            return -1;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        $exitCode = -1;
        while (true) {
            $out = fread($pipes[1], 8192);
            $err = fread($pipes[2], 8192);
            if (is_string($out) && '' !== $out) {
                $buffer .= $out;
                $buffer = $this->emitCompleteLines($buffer);
            }
            if (is_string($err) && '' !== $err) {
                $this->stderr .= $err;
            }

            // proc_get_status() gives the real exit code once, when the process has ended.
            $status = proc_get_status($process);
            if ( ! $status['running']) {
                $exitCode = $status['exitcode'];
                $buffer .= (string) stream_get_contents($pipes[1]);
                $this->stderr .= (string) stream_get_contents($pipes[2]);

                break;
            }
            if ('' === $out && '' === $err) {
                usleep(50000);
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ('' !== $buffer) {
            ($this->output)($this->clean($buffer));
        }
        if (0 !== $exitCode) {
            DolibarrHelper::log("{$scriptName} exited with code {$exitCode}: ".trim($this->stderr), LOG_ERR);
        }

        return $exitCode;
    }

    private function emitCompleteLines(string $buffer): string
    {
        // Keep the last, possibly incomplete, line so strip_tags never cuts a tag in two.
        // explode() works on bytes: a multibyte character split between two reads stays intact.
        $lines = explode("\n", $buffer);
        $rest = (string) array_pop($lines);
        if ([] !== $lines) {
            ($this->output)($this->clean(implode("\n", $lines)."\n"));
        }

        return $rest;
    }

    private function clean(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    }
}
