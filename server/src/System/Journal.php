<?php

namespace Dolicli\System;

use Dolicli\Cli\Json;

/**
 * Shared trace of the write commands run on an instance (DOL_DATA_ROOT/dolicli/journal.log),
 * one JSON object per line: date, SSH account, Dolibarr user, command, exit code.
 */
final class Journal
{
    const MAX_BYTES = 5242880;

    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function forDataRoot(string $dataRoot): self
    {
        return new self(rtrim($dataRoot, '/') . '/dolicli/journal.log');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param array<int, string> $arguments Arguments with sensitive values already masked
     */
    public function record(string $command, array $arguments, string $dolibarrUser, int $exitCode)
    {
        try {
            Files::ensureDir(dirname($this->path), 0750);
        } catch (\Exception $e) {
            Log::error('journal: ' . $e->getMessage());

            return;
        }
        Files::rotate($this->path, self::MAX_BYTES);
        $line = Json::encode([
            'date' => date('c'),
            'operator' => Posix::operator(),
            'sudo_user' => Posix::sudoUser(),
            'account' => Posix::currentUserName(),
            'dolibarr_user' => $dolibarrUser,
            'command' => $command,
            'arguments' => $arguments,
            'exit_code' => $exitCode,
        ], false);
        if (false === @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX)) {
            Log::error("journal: cannot write {$this->path}");
        }
    }

    /**
     * Last entries, most recent last.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tail(int $count): array
    {
        $content = @file_get_contents($this->path);
        if (!is_string($content)) {
            return [];
        }
        $entries = [];
        foreach (array_slice(array_filter(explode("\n", $content)), -$count) as $line) {
            $entry = Json::decode($line);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
