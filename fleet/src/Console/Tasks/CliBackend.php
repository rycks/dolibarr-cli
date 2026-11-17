<?php

declare(strict_types=1);

namespace Dolifleet\Console\Tasks;

use Dolifleet\Paths;
use RuntimeException;
use Symfony\Component\Process\Process;
use Phar;

/**
 * Runs dolifleet itself, the way an administrator would, with --json.
 *
 * - Reads run as child processes of the console (stopped if the console quits).
 * - Writes run detached (setsid), their output in a log file of the data directory and their
 *   exit code in a file next to it: quitting the console never cuts a write in the middle, and
 *   the next console finds them again.
 *
 * The environment says DOLIFLEET_FRONTEND=console: the journal records where a write came from.
 */
final class CliBackend implements Backend
{
    /** @var array<string, array{task: Task, process: Process}> */
    private array $reads = [];

    /** @var array<string, Task> */
    private array $writes = [];

    /** @var array<string, Task> */
    private array $tasks = [];

    private int $sequence = 0;

    /**
     * @param list<string> $dolifleet Command starting dolifleet (php and the script or the phar)
     */
    public function __construct(private readonly array $dolifleet, private readonly string $dir = '')
    {
        $this->resume();
    }

    /**
     * PHP and the dolifleet being run (the phar once built, fleet/bin/dolifleet otherwise).
     *
     * @return list<string>
     */
    public static function self(): array
    {
        $phar = class_exists('Phar') ? Phar::running(false) : '';
        $script = '' !== $phar ? $phar : dirname(__DIR__, 3).'/bin/dolifleet';

        return [PHP_BINARY, $script];
    }

    public function start(array $args, string $purpose, array $instances, bool $write, array $options = [], array $meta = []): Task
    {
        $id = date('YmdHis').'-'.str_pad((string) ++$this->sequence, 3, '0', STR_PAD_LEFT).'-'.bin2hex(random_bytes(2));
        $task = new Task($id, $args, $purpose, $instances, $write, microtime(true), $meta);
        $this->tasks[$id] = $task;
        $command = array_merge($this->dolifleet, ['--no-interaction'], Command::withOptions($args, array_merge(['--json'], $options)));
        $env = ['DOLIFLEET_FRONTEND' => 'console'];

        if ( ! $write) {
            // The environment of this process as it is now (getenv() sees putenv() too).
            $process = new Process($command, null, getenv() + $env, null, null);
            $process->start();
            $this->reads[$id] = ['task' => $task, 'process' => $process];

            return $task;
        }

        $dir = $this->directory();
        Paths::ensure($dir);
        $base = $dir.'/'.$id;
        $meta = ['id' => $id, 'args' => $args, 'purpose' => $purpose, 'instances' => $instances, 'started_at' => $task->startedAt, 'meta' => $task->meta];
        if (false === file_put_contents($base.'.json', json_encode($meta, JSON_UNESCAPED_SLASHES))) {
            throw new RuntimeException("Cannot write {$base}.json");
        }
        $shell = implode(' ', array_map('escapeshellarg', $command))
            .' > '.escapeshellarg($base.'.out').' 2> '.escapeshellarg($base.'.err').' < /dev/null; echo $? > '.escapeshellarg($base.'.exit');
        // setsid detaches from the session of the console (Linux); nohup where it is missing (macOS).
        $launcher = self::detacher().' sh -c '.escapeshellarg($shell).' > /dev/null 2>&1 < /dev/null &';
        $exports = '';
        foreach ($env as $name => $value) {
            $exports .= $name.'='.escapeshellarg($value).' ';
        }
        $output = [];
        $code = 0;
        exec($exports.$launcher, $output, $code);
        if (0 !== $code) {
            $task->exitCode = 1;
            $task->stderr = 'Cannot start the detached command (setsid or nohup).';
            $task->endedAt = microtime(true);
        }
        $this->writes[$id] = $task;

        return $task;
    }

    public function poll(): array
    {
        $finished = [];
        foreach ($this->reads as $id => ['task' => $task, 'process' => $process]) {
            if ($process->isRunning()) {
                continue;
            }
            $task->exitCode = (int) $process->getExitCode();
            $task->stdout = $process->getOutput();
            $task->stderr = $process->getErrorOutput();
            $task->endedAt = microtime(true);
            $task->json = self::decode($task->stdout);
            unset($this->reads[$id]);
            $finished[] = $task;
        }
        foreach ($this->writes as $id => $task) {
            if ($task->finished()) {
                unset($this->writes[$id]);
                $finished[] = $task;
                continue;
            }
            $base = $this->directory().'/'.$id;
            if ( ! is_file($base.'.exit')) {
                continue;
            }
            $code = trim((string) file_get_contents($base.'.exit'));
            if ('' === $code) {
                // The shell is writing the file: read it at the next round.
                continue;
            }
            $task->exitCode = (int) $code;
            $task->stdout = (string) @file_get_contents($base.'.out');
            $task->stderr = (string) @file_get_contents($base.'.err');
            $task->endedAt = (float) (filemtime($base.'.exit') ?: time());
            $task->json = self::decode($task->stdout);
            unset($this->writes[$id]);
            $finished[] = $task;
        }

        return $finished;
    }

    public function all(): array
    {
        $tasks = array_values($this->tasks);
        usort($tasks, static fn (Task $a, Task $b): int => $a->startedAt <=> $b->startedAt);

        return $tasks;
    }

    public function runningReads(): int
    {
        return count($this->reads);
    }

    public function directory(): string
    {
        return '' === $this->dir ? Paths::dataDir().'/console-tasks' : $this->dir;
    }

    /**
     * Writes of earlier sessions still running, or finished less than a day ago: shown again
     * in the band of operations. Older files are removed.
     */
    private function resume(): void
    {
        foreach (glob($this->directory().'/*.json') ?: [] as $file) {
            $base = mb_substr($file, 0, -5);
            $meta = json_decode((string) file_get_contents($file), true);
            $age = time() - (int) (filemtime($file) ?: 0);
            if ( ! is_array($meta) || $age > 7 * 86400) {
                foreach (['.json', '.out', '.err', '.exit'] as $suffix) {
                    @unlink($base.$suffix);
                }
                continue;
            }
            if (is_file($base.'.exit') && $age > 86400) {
                continue;
            }
            $task = new Task(
                (string) $meta['id'],
                array_values(array_map('strval', (array) ($meta['args'] ?? []))),
                (string) ($meta['purpose'] ?? ''),
                array_values(array_map('strval', (array) ($meta['instances'] ?? []))),
                true,
                (float) ($meta['started_at'] ?? 0),
                ['resumed' => true] + (array) ($meta['meta'] ?? []),
            );
            $this->tasks[$task->id] = $task;
            $this->writes[$task->id] = $task;
        }
    }

    private static function detacher(): string
    {
        static $detacher = null;
        if (null === $detacher) {
            $output = [];
            $code = 1;
            exec('command -v setsid 2>/dev/null', $output, $code);
            $detacher = 0 === $code ? 'setsid' : 'nohup';
        }

        return $detacher;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decode(string $output): ?array
    {
        $json = json_decode($output, true);

        return is_array($json) ? $json : null;
    }
}
