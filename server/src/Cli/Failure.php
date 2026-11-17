<?php

namespace Dolicli\Cli;

use RuntimeException;

/**
 * A command that cannot complete: stable error code, message and process exit code.
 */
class Failure extends RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $exitCode;

    /** @var array<string, mixed> */
    private $details;

    /**
     * @param array<string, mixed> $details Extra machine-readable data put in the envelope
     */
    public function __construct(string $errorCode, string $message, int $exitCode = ExitCode::FAILURE, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->exitCode = $exitCode;
        $this->details = $details;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    public static function usage(string $errorCode, string $message): self
    {
        return new self($errorCode, $message, ExitCode::USAGE);
    }

    public static function environment(string $errorCode, string $message): self
    {
        return new self($errorCode, $message, ExitCode::ENVIRONMENT);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function operation(string $errorCode, string $message, array $details = []): self
    {
        return new self($errorCode, $message, ExitCode::FAILURE, $details);
    }
}
