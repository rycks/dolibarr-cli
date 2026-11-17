<?php

namespace Dolicli\Cli;

/**
 * Standard streams: stdout carries the result only, stderr the progress, warnings and traces.
 */
final class Console
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var resource */
    private $stdin;

    /** @var bool */
    private $quiet = false;

    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($stdin, $stdout, $stderr)
    {
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public static function standard(): self
    {
        return new self(STDIN, STDOUT, STDERR);
    }

    public function setQuiet(bool $quiet): self
    {
        $this->quiet = $quiet;

        return $this;
    }

    public function out(string $text)
    {
        fwrite($this->stdout, $text);
    }

    public function err(string $text)
    {
        fwrite($this->stderr, $text);
    }

    /**
     * Progress message on stderr, hidden by --quiet.
     */
    public function progress(string $message)
    {
        if (!$this->quiet) {
            $this->err(Text::safe($message) . "\n");
        }
    }

    public function warning(string $message)
    {
        $this->err('Warning: ' . Text::safe($message) . "\n");
    }

    public function stdoutIsTerminal(): bool
    {
        return self::isTerminal($this->stdout);
    }

    public function stdinIsTerminal(): bool
    {
        return self::isTerminal($this->stdin);
    }

    /**
     * posix_isatty() when posix is present, stream_isatty() from PHP 7.2, otherwise
     * the stream is considered non interactive.
     *
     * @param resource $stream
     */
    public static function isTerminal($stream): bool
    {
        if (function_exists('posix_isatty')) {
            return @posix_isatty($stream);
        }
        if (function_exists('stream_isatty')) {
            // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.stream_isattyFound -- guarded by function_exists()
            return stream_isatty($stream);
        }

        return false;
    }

    /**
     * One line from stdin, null at end of input.
     *
     * @return string|null
     */
    public function readLine()
    {
        $line = fgets($this->stdin);
        if (false === $line) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Ask a question on stderr and read the answer, without echo for secrets.
     *
     * @return string|null
     */
    public function ask(string $question, bool $secret = false)
    {
        $this->err($question);
        $echoOff = $secret && $this->stdinIsTerminal();
        if ($echoOff) {
            shell_exec('stty -echo 2>/dev/null');
        }
        try {
            $answer = $this->readLine();
        } finally {
            if ($echoOff) {
                shell_exec('stty echo 2>/dev/null');
                $this->err("\n");
            }
        }

        return $answer;
    }
}
