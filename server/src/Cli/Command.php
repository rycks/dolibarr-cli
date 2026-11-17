<?php

namespace Dolicli\Cli;

/**
 * A dolicli command: metadata (manifest, help, validation) and behaviour.
 *
 * execute() returns the data of the envelope, or throws a Failure. Progress goes
 * through $ctx->progress() (stderr), never to stdout.
 */
abstract class Command
{
    const READ = 'read';
    const WRITE = 'write';
    const DESTRUCTIVE = 'destructive';

    /**
     * Command words: 'user list', 'system maintenance enable', 'version'.
     */
    abstract public function name(): string;

    /**
     * One line description for the general help and the manifest.
     */
    abstract public function summary(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function execute(Context $ctx): array;

    /**
     * read, write or destructive: drives the lock, the journal and the guards of the pilot.
     */
    public function category(): string
    {
        return self::READ;
    }

    /**
     * Whether master.inc.php must be loaded before execute().
     */
    public function needsDolibarr(): bool
    {
        return true;
    }

    /**
     * Commands that need an instance but not Dolibarr (monitor show...).
     */
    public function needsInstance(): bool
    {
        return $this->needsDolibarr();
    }

    /**
     * Whether the pilot may run it on several instances at once.
     */
    public function allowedInBatch(): bool
    {
        return self::DESTRUCTIVE !== $this->category();
    }

    /**
     * Whether the command may run as root without --allow-root.
     */
    public function allowedAsRoot(): bool
    {
        return false;
    }

    /**
     * @return array<int, Param>
     */
    public function params(): array
    {
        return [];
    }

    /**
     * Extra lines of the detailed help.
     */
    public function help(): string
    {
        return '';
    }

    /**
     * @return array<int, string>
     */
    public function examples(): array
    {
        return [];
    }

    /**
     * Output formats this command supports besides text and json.
     *
     * @return array<int, string>
     */
    public function extraFormats(): array
    {
        return [];
    }

    /**
     * Text rendering of the data returned by execute().
     *
     * @param array<string, mixed> $data
     */
    public function renderText(array $data): string
    {
        return TextRenderer::generic($data);
    }

    /**
     * Rendering for formats other than text and json (prometheus, nagios).
     * Returns the output and the exit code.
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: int}
     */
    public function renderFormat(string $format, array $data): array
    {
        return ['', ExitCode::USAGE];
    }

    /**
     * Output that replaces the envelope (state file, Zabbix discovery): null to use the envelope.
     *
     * @param array<string, mixed> $data
     * @return string|null
     */
    public function rawResult(array $data)
    {
        return null;
    }

    /**
     * Called when Dolibarr exits or fails while loading (database unreachable...),
     * before the error envelope is written.
     */
    public function dolibarrLoadFailed(Context $ctx, string $reason)
    {
    }

    /**
     * Group of the command (first word).
     */
    public function group(): string
    {
        $words = explode(' ', $this->name());

        return $words[0];
    }

    /**
     * @return array<int, string>
     */
    public function sensitiveParams(): array
    {
        $names = [];
        foreach ($this->params() as $param) {
            if ($param->isSensitive()) {
                $names[] = $param->name();
            }
        }

        return $names;
    }

    public function isWrite(): bool
    {
        return self::READ !== $this->category();
    }
}
