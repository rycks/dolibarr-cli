<?php

namespace Dolicli\System;

use Dolicli\Cli\ExitCode;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;

/**
 * Exclusive, non blocking lock of an instance (DOL_DATA_ROOT/dolicli/lock).
 *
 * The file is opened in mode 'c': mode 'w' would erase the information of the
 * holder before the lock is taken. Once locked, the holder writes its pid,
 * command, start date and sudo caller.
 */
final class Lock
{
    /** @var string */
    private $path;

    /** @var resource|null */
    private $handle;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public static function forDataRoot(string $dataRoot): self
    {
        return new self(rtrim($dataRoot, '/') . '/dolicli/lock');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Take the lock or throw a Failure with exit code 5 and the holder's information.
     */
    public function acquire(string $command)
    {
        Files::ensureDir(dirname($this->path), 0750);
        $handle = @fopen($this->path, 'c+');
        if (false === $handle) {
            Log::error("cannot open lock file {$this->path}");
            throw Failure::operation('LOCK_UNAVAILABLE', "Cannot open the lock file {$this->path}.");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $holder = self::parse((string) stream_get_contents($handle));
            fclose($handle);
            $message = 'Another dolicli operation is running on this instance';
            if ([] !== $holder) {
                $message .= sprintf(
                    ': %s (pid %s, started %s by %s)',
                    isset($holder['command']) ? $holder['command'] : '?',
                    isset($holder['pid']) ? $holder['pid'] : '?',
                    isset($holder['started_at']) ? $holder['started_at'] : '?',
                    isset($holder['operator']) ? $holder['operator'] : '?'
                );
            }
            Log::warning($message);
            throw new Failure('LOCKED', $message . '.', ExitCode::LOCKED, ['holder' => Json::object($holder)]);
        }

        $info = Json::encode([
            'pid' => getmypid(),
            'command' => $command,
            'started_at' => date('c'),
            'operator' => Posix::operator(),
            'sudo_user' => Posix::sudoUser(),
        ], false);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $info . "\n");
        fflush($handle);
        $this->handle = $handle;
    }

    public function release()
    {
        if (null === $this->handle) {
            return;
        }
        ftruncate($this->handle, 0);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Information of the current holder, empty array when the lock is free.
     *
     * @return array<string, mixed>
     */
    public function holder(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $handle = @fopen($this->path, 'r');
        if (false === $handle) {
            return [];
        }
        if (flock($handle, LOCK_SH | LOCK_NB)) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return [];
        }
        $holder = self::parse((string) stream_get_contents($handle));
        fclose($handle);

        return [] === $holder ? ['command' => 'unknown'] : $holder;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parse(string $content): array
    {
        $data = Json::decode(trim($content));

        return is_array($data) ? $data : [];
    }
}
