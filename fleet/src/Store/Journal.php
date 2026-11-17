<?php

declare(strict_types=1);

namespace Dolifleet\Store;

use Dolifleet\Paths;
use RuntimeException;

/**
 * Journal of the actions of the pilot (~/.local/share/dolifleet/journal.jsonl): one line before
 * every write (intention), one after (result). Sensitive parameters are masked from the
 * manifest. Append only, never rewritten; rotated by size with archives.
 */
final class Journal
{
    public const MAX_BYTES = 10485760;

    public function __construct(private readonly string $file = '')
    {
    }

    public function path(): string
    {
        return '' === $this->file ? Paths::journal() : $this->file;
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $sensitive
     * @param array<string, mixed> $extra
     */
    public function intention(string $run, string $target, string $command, array $arguments, array $sensitive, array $extra = []): void
    {
        $this->append(['phase' => 'intention', 'run' => $run, 'target' => $target, 'command' => $command, 'arguments' => self::mask($arguments, $sensitive)] + $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function result(string $run, string $instance, string $command, int $exitCode, string $summary, array $extra = []): void
    {
        $this->append(['phase' => 'result', 'run' => $run, 'instance' => $instance, 'command' => $command, 'exit_code' => $exitCode, 'summary' => $summary] + $extra);
    }

    /**
     * Last entries, most recent last, optionally for one instance or target.
     *
     * @return list<array<string, mixed>>
     */
    public function tail(int $count, string $instance = ''): array
    {
        $lines = is_file($this->path()) ? (file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ( ! is_array($entry)) {
                continue;
            }
            if ('' !== $instance && ($entry['instance'] ?? null) !== $instance && ! str_contains((string) ($entry['target'] ?? ''), $instance)) {
                continue;
            }
            $entries[] = $entry;
        }

        return array_slice($entries, -$count);
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $sensitive
     * @return list<string>
     */
    public static function mask(array $arguments, array $sensitive): array
    {
        $masked = [];
        foreach ($arguments as $argument) {
            foreach ($sensitive as $name) {
                if (str_starts_with($argument, $name.'=') || str_starts_with($argument, '--'.$name.'=')) {
                    $argument = mb_substr($argument, 0, (int) mb_strpos($argument, '=') + 1).'***';
                }
            }
            $masked[] = $argument;
        }

        return $masked;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function append(array $entry): void
    {
        $file = $this->path();
        Paths::ensure(dirname($file));
        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            if ( ! rename($file, $file.'.'.date('YmdHis'))) {
                throw new RuntimeException("Cannot rotate {$file}");
            }
        }
        $entry = ['date' => date('c'), 'operator' => get_current_user()] + $entry;
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        if (false === file_put_contents($file, $line, FILE_APPEND | LOCK_EX)) {
            throw new RuntimeException("Cannot write the journal {$file}");
        }
        @chmod($file, 0600);
    }
}
