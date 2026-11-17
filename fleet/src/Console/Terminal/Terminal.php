<?php

declare(strict_types=1);

namespace Dolifleet\Console\Terminal;

use RuntimeException;

/**
 * The real terminal: raw keyboard through stty, alternate screen, hidden cursor, size.
 *
 * The state of the terminal is saved (stty -g) before anything changes and restored on every
 * way out: normal end, exception, fatal error (shutdown function), SIGTERM/SIGHUP when pcntl is
 * available. Ctrl-C arrives as a key (isig off): the console decides what it means.
 */
final class Terminal
{
    private string $saved = '';

    private bool $open = false;

    private bool $resized = true;

    private int $cols = 80;

    private int $rows = 24;

    private float $sizeCheckedAt = 0.0;

    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    public function __construct()
    {
        $this->in = STDIN;
        $this->out = STDOUT;
    }

    public static function interactive(): bool
    {
        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    public function open(): void
    {
        if ($this->open) {
            return;
        }
        if ( ! self::interactive()) {
            throw new RuntimeException('The console needs a terminal: use dolifleet --plain or a command in scripts.');
        }
        $saved = $this->stty('-g');
        // Restored later through a shell: only the characters stty -g prints are accepted.
        if (null === $saved || 1 !== preg_match('/^[0-9A-Za-z:=-]+$/', trim($saved))) {
            throw new RuntimeException('Cannot read the state of the terminal (stty -g).');
        }
        $this->saved = trim($saved);
        if (null === $this->stty('-icanon -echo -isig -ixon -icrnl min 1 time 0')) {
            throw new RuntimeException('Cannot switch the terminal to raw mode (stty).');
        }
        $this->open = true;
        register_shutdown_function(function (): void {
            $this->close();
        });
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGWINCH, function (): void {
                $this->resized = true;
            });
            foreach ([SIGTERM, SIGHUP] as $signal) {
                pcntl_signal($signal, function () use ($signal): void {
                    $this->close();
                    exit(128 + $signal);
                });
            }
        }
        stream_set_blocking($this->in, false);
        $this->write("\e[?1049h\e[?25l\e[H\e[2J");
    }

    public function close(): void
    {
        if ( ! $this->open) {
            return;
        }
        $this->open = false;
        $this->write("\e[0m\e[?25h\e[?1049l");
        stream_set_blocking($this->in, true);
        if (null === $this->stty($this->saved)) {
            // Last resort: a sane terminal rather than a raw one.
            $this->stty('sane');
        }
        if (function_exists('pcntl_signal')) {
            foreach ([SIGWINCH, SIGTERM, SIGHUP] as $signal) {
                pcntl_signal($signal, SIG_DFL);
            }
        }
    }

    public function write(string $bytes): void
    {
        $length = mb_strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $count = @fwrite($this->out, mb_substr($bytes, $written));
            if (false === $count || 0 === $count) {
                return;
            }
            $written += $count;
        }
        fflush($this->out);
    }

    /**
     * Bytes typed, waiting at most $timeoutMs for the first one.
     */
    public function read(int $timeoutMs): string
    {
        $read = [$this->in];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, max(0, $timeoutMs) * 1000);
        if (false === $ready || 0 === $ready) {
            return '';
        }
        $bytes = '';
        while (true) {
            $chunk = fread($this->in, 1024);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /**
     * Columns and rows. Read again after SIGWINCH, or every second without pcntl.
     *
     * @return array{0: int, 1: int}
     */
    public function size(): array
    {
        $now = microtime(true);
        if ($this->resized || $now - $this->sizeCheckedAt > 1.0) {
            $this->resized = false;
            $this->sizeCheckedAt = $now;
            $size = $this->stty('size');
            if (null !== $size && 1 === preg_match('/^(\d+)\s+(\d+)$/', trim($size), $m) && (int) $m[1] > 0) {
                $this->rows = (int) $m[1];
                $this->cols = (int) $m[2];
            } else {
                $this->cols = max(20, (int) (getenv('COLUMNS') ?: 80));
                $this->rows = max(5, (int) (getenv('LINES') ?: 24));
            }
        }

        return [$this->cols, $this->rows];
    }

    /**
     * stty on the terminal of the console, its output or null on failure.
     */
    private function stty(string $arguments): ?string
    {
        $output = [];
        $code = 0;
        exec('stty '.$arguments.' < /dev/tty 2>/dev/null', $output, $code);

        return 0 === $code ? implode("\n", $output) : null;
    }
}
