<?php

namespace Dolicli\Cli;

use Dolicli\Instance\Instance;
use Dolicli\Instance\Resolver;
use Dolicli\System\Log;

/**
 * Everything a command needs at run time: arguments, console, instance, options,
 * warnings, cleanups.
 */
final class Context
{
    /** @var Args */
    private $args;

    /** @var Console */
    private $console;

    /** @var string */
    private $format;

    /** @var Instance|null */
    private $instance;

    /** @var array<int, array{code: string, message: string}> */
    private $warnings = [];

    /** @var array<int, array{code: string, message: string}> */
    private $errors = [];

    /** @var int */
    private $exitCode = ExitCode::SUCCESS;

    /** @var string */
    private $actingUser = '';

    /** @var array<int, callable> */
    private $cleanups = [];

    /** @var bool */
    private $interactive;

    public function __construct(Args $args, Console $console, string $format, bool $interactive)
    {
        $this->args = $args;
        $this->console = $console;
        $this->format = $format;
        $this->interactive = $interactive;
    }

    public function args(): Args
    {
        return $this->args;
    }

    public function console(): Console
    {
        return $this->console;
    }

    public function format(): string
    {
        return $this->format;
    }

    /**
     * @return string|null
     */
    public function value(string $name)
    {
        return $this->args->value($name);
    }

    public function flag(string $name): bool
    {
        return $this->args->flag($name);
    }

    public function dryRun(): bool
    {
        return $this->args->flag('dry-run');
    }

    public function showSecrets(): bool
    {
        return $this->args->flag('show-secrets');
    }

    /**
     * Dolibarr entity (--entity), 1 by default.
     */
    public function entity(): int
    {
        $entity = $this->args->value('entity');

        return null === $entity ? 1 : (int) $entity;
    }

    /**
     * The instance, resolved on first use.
     */
    public function instance(): Instance
    {
        if (null === $this->instance) {
            $this->instance = Resolver::resolve($this->args->value('instance'), $this->args->value('dolpath'));
        }

        return $this->instance;
    }

    /**
     * documents/ of the instance: DOL_DATA_ROOT once Dolibarr is loaded, read in conf.php otherwise.
     */
    public function dataRoot(): string
    {
        if (defined('DOL_DATA_ROOT')) {
            return (string) constant('DOL_DATA_ROOT');
        }
        $dataRoot = $this->instance()->dataRoot();
        if ('' === $dataRoot || !is_dir($dataRoot)) {
            throw Failure::environment('DOCUMENTS_NOT_FOUND', "Cannot find the documents directory of {$this->instance()->htdocs()} (dolibarr_main_data_root in conf.php).");
        }

        return $dataRoot;
    }

    /**
     * @return Instance|null
     */
    public function instanceIfResolved()
    {
        return $this->instance;
    }

    public function setInstance(Instance $instance)
    {
        $this->instance = $instance;
    }

    public function setActingUser(string $login)
    {
        $this->actingUser = $login;
    }

    public function actingUser(): string
    {
        return $this->actingUser;
    }

    public function progress(string $message)
    {
        $this->console->progress($message);
    }

    /**
     * Warning for the envelope (and stderr in text format).
     */
    public function warn(string $code, string $message)
    {
        $this->warnings[] = ['code' => $code, 'message' => $message];
        Log::warning($message);
        if ('text' === $this->format) {
            $this->console->warning($message);
        }
    }

    /**
     * @return array<int, array{code: string, message: string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Record an error while still returning data (filecheck with modified files,
     * doctor with problems): the envelope carries both, the exit code is set.
     */
    public function addError(string $code, string $message, int $exitCode = ExitCode::FAILURE)
    {
        $this->errors[] = ['code' => $code, 'message' => $message];
        Log::error($message);
        if (ExitCode::SUCCESS === $this->exitCode) {
            $this->exitCode = $exitCode;
        }
    }

    /**
     * @return array<int, array{code: string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Ask for a confirmation: --yes answers yes; without a terminal, exit code 4.
     */
    public function confirm(string $question): bool
    {
        if ($this->args->flag('yes')) {
            return true;
        }
        if (!$this->interactive) {
            throw new Failure('CONFIRMATION_REQUIRED', $question . ' Confirmation required: run in a terminal or add --yes.', ExitCode::CONFIRMATION);
        }
        $answer = $this->console->ask($question . ' (yes/no) ');
        if (null === $answer) {
            throw new Failure('CONFIRMATION_REQUIRED', 'No answer on standard input: add --yes to run without prompt.', ExitCode::CONFIRMATION);
        }

        return in_array(strtolower(trim($answer)), ['yes', 'y', 'oui', 'o'], true);
    }

    /**
     * Stronger confirmation: the operator types an exact word.
     */
    public function confirmTyping(string $question, string $expected): bool
    {
        if ($this->args->flag('yes')) {
            return true;
        }
        if (!$this->interactive) {
            throw new Failure('CONFIRMATION_REQUIRED', $question . ' Confirmation required: run in a terminal or add --yes.', ExitCode::CONFIRMATION);
        }
        $answer = $this->console->ask($question . " Type {$expected} to confirm: ");

        return null !== $answer && $expected === trim($answer);
    }

    /**
     * A secret: value of a sensitive parameter given in the stream of the ephemeral mode,
     * or read on stdin with --password-stdin, or asked without echo on a terminal.
     */
    public function secret(string $param, string $prompt): string
    {
        $value = $this->args->value($param);
        if (null !== $value && '' !== $value) {
            return $value;
        }
        if ($this->args->flag('password-stdin')) {
            $line = $this->console->readLine();
            if (null === $line || '' === trim($line)) {
                throw Failure::usage('SECRET_MISSING', '--password-stdin given but nothing was read on standard input.');
            }

            return $line;
        }
        if (!$this->interactive) {
            throw Failure::usage('SECRET_MISSING', "No {$param} given: use --password-stdin in scripts.");
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $answer = $this->console->ask($prompt, true);
            if (null === $answer) {
                break;
            }
            if ('' !== trim($answer)) {
                return $answer;
            }
        }
        throw Failure::usage('SECRET_MISSING', 'No value entered.');
    }

    /**
     * Register a cleanup run at the end of the command, also after a fatal error
     * or an exit() inside Dolibarr. Cleanups run in reverse order, once.
     */
    public function defer(callable $cleanup)
    {
        $this->cleanups[] = $cleanup;
    }

    public function runCleanups()
    {
        while ([] !== $this->cleanups) {
            $cleanup = array_pop($this->cleanups);
            try {
                call_user_func($cleanup);
            } catch (\Throwable $e) {
                Log::error('cleanup failed: ' . $e->getMessage());
            }
        }
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }
}
