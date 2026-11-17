<?php

namespace Dolicli\Upgrade;

use Dolicli\System\Log;
use Dolicli\System\Process;

/**
 * Run a Dolibarr install/ script (upgrade.php, upgrade2.php, step5.php, repair.php)
 * in a separate PHP process.
 *
 * Including them in the dolicli process does not work: they bootstrap through
 * install/inc.php, read their parameters from $argv or $_GET, and call exit(), which
 * would skip our cleanup (lock file, maintenance mode, ownership).
 */
final class InstallScriptRunner
{
    /** @var callable */
    private $output;

    /** @var string */
    private $php;

    /** @var string */
    private $stderr = '';

    /** @var string */
    private $pending = '';

    /**
     * @param callable $output Receives the script output, HTML stripped, line by line
     * @param string $php PHP binary (default: the one running dolicli)
     */
    public function __construct(callable $output, string $php = '')
    {
        $this->output = $output;
        $this->php = '' === $php ? PHP_BINARY : $php;
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    /**
     * Run install/$scriptName with $args (and optionally $_GET-style $query for web-only scripts).
     *
     * @param array<int, string> $args
     * @param array<string, string> $query Parameters given in $_GET, for scripts reading GETPOST()
     * @param array<int, string> $defines Constants defined before the script runs
     * @return int Process exit code (-1 if it could not be started)
     */
    public function run(string $installDir, string $scriptName, array $args = [], array $query = [], array $defines = []): int
    {
        $this->stderr = '';
        $scriptPath = rtrim($installDir, '/') . '/' . $scriptName;
        if (!is_file($scriptPath)) {
            $this->stderr = "Install script not found: {$scriptPath}";
            Log::error($this->stderr);

            return -1;
        }

        if ([] === $query && [] === $defines) {
            $cmd = array_merge([$this->php, $scriptPath], $args);
        } else {
            // GETPOST() reads $_GET: fill it (and the constants) from a small wrapper, then run the script.
            $wrapper = '$_GET = ' . var_export($query, true) . ';';
            foreach ($defines as $constant) {
                $wrapper .= ' define(' . var_export($constant, true) . ', 1);';
            }
            $wrapper .= ' $argv = array_merge(array(' . var_export($scriptPath, true) . '), array_slice($argv, 1)); $argc = count($argv);'
                . ' require ' . var_export($scriptPath, true) . ';';
            $cmd = array_merge([$this->php, '-r', $wrapper, '--'], $args);
        }

        $process = new Process();
        $self = $this;
        $exitCode = $process->run($cmd, [], null, dirname($scriptPath), function ($chunk) use ($self) {
            $self->receive($chunk);
        });
        $this->stderr = $process->stderr();
        if ('' !== $this->pending) {
            call_user_func($this->output, self::clean($this->pending));
            $this->pending = '';
        }
        if (0 !== $exitCode) {
            Log::error("{$scriptName} exited with code {$exitCode}: " . trim($this->stderr));
        }

        return $exitCode;
    }

    /**
     * Output chunk of the script: complete lines are emitted, the last partial line is kept
     * so strip_tags never cuts a tag in two.
     */
    public function receive(string $chunk)
    {
        $this->pending .= $chunk;
        $position = strrpos($this->pending, "\n");
        if (false === $position) {
            return;
        }
        $complete = substr($this->pending, 0, $position + 1);
        $this->pending = (string) substr($this->pending, $position + 1);
        call_user_func($this->output, self::clean($complete));
    }

    private static function clean(string $html): string
    {
        $text = (string) preg_replace('#<br\s*/?>|</(?:p|div|tr|li|h[1-6]|table)>#i', "\n", $html);
        $text = (string) preg_replace('#</t[dh]>#i', ' ', $text);

        return html_entity_decode(strip_tags($text), ENT_QUOTES);
    }
}
