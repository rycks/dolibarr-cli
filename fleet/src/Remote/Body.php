<?php

declare(strict_types=1);

namespace Dolifleet\Remote;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * The dolicli body embedded in the pilot: sent on the standard input of PHP in the ephemeral
 * mode, installed by dolifleet setup, and source of the manifest.
 */
final class Body
{
    private string $content;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public function __construct(private readonly string $path)
    {
        $content = @file_get_contents($path);
        if ( ! is_string($content) || 1 !== preg_match("/const VERSION = '([^']+)';/", $content)) {
            throw new RuntimeException("No built dolicli body in {$path}: run make build (or set DOLIFLEET_BODY).");
        }
        $this->content = $content;
    }

    public static function locate(): self
    {
        $env = getenv('DOLIFLEET_BODY');
        if (is_string($env) && '' !== $env) {
            return new self($env);
        }
        foreach ([dirname(__DIR__, 3).'/dist/dolicli.php', dirname(__DIR__, 2).'/dolicli.php'] as $candidate) {
            if (is_file($candidate)) {
                return new self($candidate);
            }
        }
        throw new RuntimeException('No built dolicli body found: run make build (or set DOLIFLEET_BODY).');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function version(): string
    {
        preg_match("/const VERSION = '([^']+)';/", $this->content, $m);

        return $m[1];
    }

    public function sha256(): string
    {
        return hash('sha256', $this->content);
    }

    /**
     * Major version of the envelope schema of this body.
     */
    public function schemaMajor(): string
    {
        return 1 === preg_match("/const ENVELOPE_SCHEMA = '(\\d+)\\./", $this->content, $m) ? $m[1] : '';
    }

    /**
     * Stream of the ephemeral mode: the arguments in a global namespace block (a namespace
     * declaration must be the first statement of a file), then the body. Files sent along
     * (setup: body, launcher, instances.ini) are base64 in $DOLICLI_FILES.
     *
     * @param list<string> $arguments
     * @param array<string, string> $files
     */
    public function stream(array $arguments, array $files = []): string
    {
        $prefix = '<?php namespace { $DOLICLI_ARGV = '.var_export(array_merge(['dolicli'], $arguments), true).';';
        if ([] !== $files) {
            $prefix .= ' $DOLICLI_FILES = '.var_export(array_map('base64_encode', $files), true).';';
        }

        return $prefix.' } ?>'.$this->content;
    }

    /**
     * Manifest of the commands (dolicli help --format=json, run locally on the body).
     *
     * @return array<string, array<string, mixed>>
     */
    public function manifest(): array
    {
        if (null !== $this->manifest) {
            return $this->manifest;
        }
        $process = new Process([PHP_BINARY, '--'], null, ['DOLPATH' => '/nonexistent'], $this->stream(['help', '--format=json']), 30);
        $process->run();
        $data = json_decode($process->getOutput(), true);
        if ( ! is_array($data) || ! isset($data['data']['commands'])) {
            throw new RuntimeException('Cannot read the manifest of the dolicli body: '.$process->getErrorOutput());
        }
        $this->manifest = [];
        foreach ($data['data']['commands'] as $entry) {
            $this->manifest[(string) $entry['name']] = $entry;
        }

        return $this->manifest;
    }

    /**
     * Manifest entry of the command starting the argument list (longest match), null if unknown.
     *
     * @param list<string> $arguments
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    public function commandOf(array $arguments): ?array
    {
        $words = [];
        foreach ($arguments as $argument) {
            if (str_contains($argument, '=') || str_starts_with($argument, '-')) {
                break;
            }
            $words[] = $argument;
        }
        $manifest = $this->manifest();
        for ($length = min(3, count($words)); $length >= 1; --$length) {
            $name = implode(' ', array_slice($words, 0, $length));
            if (isset($manifest[$name])) {
                return [$manifest[$name], $length];
            }
        }

        return null;
    }
}
