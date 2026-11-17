<?php

namespace Dolicli\Cli;

use Dolicli\Command\HelpCommand;
use Dolicli\Dolibarr\Loader;
use Dolicli\System\Journal;
use Dolicli\System\Lock;
use Dolicli\System\Log;
use Dolicli\System\Posix;

/**
 * Dispatcher: parse the command line, choose the command, prepare Dolibarr, run,
 * write the result in the requested format, exit with the code of the contract.
 *
 * The global code of the body (main.php) calls prepare(), includes master.inc.php
 * itself when prepare() returns its path, then calls dolibarrLoaded() and run().
 */
final class Application
{
    const STATE_NEW = 0;
    const STATE_LOADING = 1;
    const STATE_RUNNING = 2;
    const STATE_DONE = 3;

    /** @var array<int, mixed> */
    private $argv;

    /** @var bool */
    private $fromStream;

    /** @var Console */
    private $console;

    /** @var Registry */
    private $registry;

    /** @var float */
    private $start;

    /** @var int */
    private $state = self::STATE_NEW;

    /** @var int */
    private $exitCode = ExitCode::SUCCESS;

    /** @var string */
    private $format = 'text';

    /** @var Command|null */
    private $command;

    /** @var Context|null */
    private $ctx;

    /** @var Loader */
    private $loader;

    /** @var Lock|null */
    private $lock;

    /** @var bool */
    private $dolibarrLoaded = false;

    /** @var string documents/ of the instance whose lock was taken: journal of the write */
    private $journalRoot = '';

    /**
     * @param array<int, mixed> $argv Full argument vector, program name first
     * @param bool $fromStream True when the arguments come from the stream of the ephemeral mode
     * @param Console|null $console
     * @param Registry|null $registry
     */
    public function __construct(array $argv, bool $fromStream, $console = null, $registry = null)
    {
        $this->start = microtime(true);
        $this->argv = $argv;
        $this->fromStream = $fromStream;
        $this->console = null === $console ? Console::standard() : $console;
        $this->registry = null === $registry ? Registry::standard() : $registry;
        $this->loader = new Loader();
        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * Parse and validate the command line. Returns the path of master.inc.php when the
     * command needs Dolibarr, an empty string otherwise (or when the run is already over).
     */
    public function prepare(): string
    {
        try {
            $args = new Args(array_slice($this->argv, 1), !$this->fromStream);
            $this->format = $this->chooseFormat($args);
            $this->console->setQuiet($args->flag('quiet'));

            if ([] !== $args->invalid()) {
                throw Failure::usage('INVALID_ARGUMENT', 'Invalid argument: ' . implode(' ', $args->invalid()) . '.');
            }

            list($command, $used) = $this->findCommand($args);
            $this->command = $command;
            $interactive = !$this->fromStream && $this->console->stdinIsTerminal();
            $this->ctx = new Context($args->shiftWords($used), $this->console, $this->format, $interactive);

            if ($args->flag('help') && !$command instanceof HelpCommand) {
                $this->writeHelp($command);
                $this->state = self::STATE_DONE;

                return '';
            }

            $this->validate($command, $this->ctx->args());

            if ($command->needsInstance()) {
                $this->ctx->instance();
            }
            if (!$command->needsDolibarr()) {
                return '';
            }

            if (Posix::isRoot() && !$command->allowedAsRoot() && !$args->flag('allow-root')) {
                throw new Failure(
                    'ROOT_REFUSED',
                    'Refusing to load Dolibarr as root: files created in documents/ would break Dolibarr. Run as the owner of documents/ (sudo -u <run_as>), or add --allow-root.',
                    ExitCode::ROOT
                );
            }

            $master = $this->loader->prepare($this->ctx->instance(), $this->ctx->entity());
            $this->state = self::STATE_LOADING;

            return $master;
        } catch (Failure $failure) {
            $this->finish([], $failure);
        } catch (\Throwable $e) {
            $this->finish([], $this->internalError($e));
        }

        return '';
    }

    /**
     * Called by the global code right after the inclusion of master.inc.php.
     */
    public function dolibarrLoaded()
    {
        $this->loader->discardOutput();
        try {
            Loader::checkLoaded();
            $this->dolibarrLoaded = true;
            $this->state = self::STATE_RUNNING;
        } catch (Failure $failure) {
            $this->loadFailed($failure->getMessage());
            $this->finish([], $failure);
        }
    }

    /**
     * Run the command and write its result. Returns the process exit code.
     */
    public function run(): int
    {
        if (self::STATE_DONE === $this->state || null === $this->command || null === $this->ctx) {
            $this->state = self::STATE_DONE;

            return $this->exitCode;
        }
        $this->state = self::STATE_RUNNING;
        $command = $this->command;
        $ctx = $this->ctx;

        $data = [];
        $failure = null;
        try {
            if ($this->usesInstanceData($command) && $command->isWrite() && !$ctx->dryRun()) {
                $this->lock = Lock::forDataRoot($ctx->dataRoot());
                $this->lock->acquire($command->name());
                $this->journalRoot = $ctx->dataRoot();
            }
            $data = $command->execute($ctx);
        } catch (Failure $f) {
            $failure = $f;
        } catch (\Throwable $e) {
            $failure = $this->internalError($e);
        }
        $ctx->runCleanups();
        $this->releaseLock();
        $this->finish($data, $failure);
        $this->journal();

        return $this->exitCode;
    }

    /**
     * Shutdown function: a premature exit (exit() inside Dolibarr, fatal error) becomes
     * an error envelope with exit code 3, after the cleanups.
     */
    public function shutdown()
    {
        if (self::STATE_LOADING !== $this->state && self::STATE_RUNNING !== $this->state) {
            return;
        }
        $loading = self::STATE_LOADING === $this->state;
        $fatal = error_get_last();
        $isFatal = null !== $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);

        if ($loading) {
            $printed = $this->loader->discardOutput();
            $reason = $isFatal ? 'Fatal error while loading Dolibarr: ' . $fatal['message'] : 'Dolibarr stopped while loading: database unreachable or invalid configuration';
            if (!$isFatal && '' !== $printed) {
                $reason .= ' (details in the system log)';
            }
            $this->loadFailed($reason);
            $failure = Failure::environment($isFatal ? 'FATAL_ERROR' : 'DOLIBARR_LOAD_FAILED', $reason . '.');
        } else {
            $reason = $isFatal ? 'Fatal error: ' . $fatal['message'] . ' in ' . $fatal['file'] . ':' . $fatal['line'] : 'Dolibarr ended the process before the command completed';
            $failure = Failure::environment($isFatal ? 'FATAL_ERROR' : 'PREMATURE_EXIT', $reason . '.');
        }
        Log::error($reason);
        if (null !== $this->ctx) {
            $this->ctx->runCleanups();
        }
        $this->releaseLock();
        $this->finish([], $failure);
        $this->journal();
        exit($this->exitCode);
    }

    private function loadFailed(string $reason)
    {
        if (null !== $this->command && null !== $this->ctx) {
            try {
                $this->command->dolibarrLoadFailed($this->ctx, $reason);
            } catch (\Throwable $e) {
                Log::error('load failure handler: ' . $e->getMessage());
            }
        }
    }

    /**
     * @return array{0: Command, 1: int}
     */
    private function findCommand(Args $args): array
    {
        $words = $args->words();
        $help = $this->registry->all()['help'];
        if ([] === $words) {
            return [$help, 0];
        }
        $found = $this->registry->find($words);
        if (null !== $found) {
            return $found;
        }
        if ([] !== $this->registry->withPrefix($words)) {
            // A group or a partial name: help of that group.
            return [$help, 0];
        }
        throw Failure::usage('UNKNOWN_COMMAND', "Unknown command '" . Text::line(implode(' ', $words)) . "'. Run '" . Usage::PROGRAM . "' for the list of commands.");
    }

    private function chooseFormat(Args $args): string
    {
        $format = $args->value('format');
        if (null === $format) {
            return $this->console->stdoutIsTerminal() ? 'text' : 'json';
        }
        if (!in_array($format, GlobalOptions::FORMATS, true)) {
            $this->format = 'text';
            throw Failure::usage('INVALID_FORMAT', "Invalid format '" . Text::line($format) . "': expected " . implode(', ', GlobalOptions::FORMATS) . '.');
        }

        return $format;
    }

    private function validate(Command $command, Args $args)
    {
        if (!in_array($this->format, array_merge(['text', 'json'], $command->extraFormats()), true)) {
            throw Failure::usage('FORMAT_NOT_SUPPORTED', "Format '{$this->format}' is not available for '{$command->name()}'.");
        }
        if ([] !== $args->words() && !$command instanceof HelpCommand) {
            throw Failure::usage('UNEXPECTED_ARGUMENT', "Unexpected argument '" . Text::line(implode(' ', $args->words())) . "' for '{$command->name()}'.");
        }

        if (null !== $args->value('instance') && null !== $args->value('dolpath')) {
            throw Failure::usage('INSTANCE_AMBIGUOUS', 'Give either --instance or --dolpath, not both.');
        }

        $known = [];
        foreach (array_merge($command->params(), GlobalOptions::all()) as $param) {
            $known[$param->name()] = $param;
        }
        foreach ($args->names() as $name) {
            if (!isset($known[$name])) {
                throw Failure::usage('UNKNOWN_PARAMETER', "Unknown parameter or option '{$name}' for '{$command->name()}' (see --help).");
            }
            $param = $known[$name];
            $value = $args->value($name);
            if ($param->isFlag()) {
                continue;
            }
            if (null === $value) {
                throw Failure::usage('MISSING_VALUE', "'{$name}' needs a value: {$param->syntax()}.");
            }
            $error = $param->validate($value);
            if ('' !== $error) {
                throw Failure::usage('INVALID_VALUE', $error);
            }
            if ($param->isSensitive() && $args->givenOnCommandLine($name)) {
                throw Failure::usage(
                    'SECRET_ON_COMMAND_LINE',
                    "'{$name}' is a secret and is refused on the command line (visible in ps and in the shell history): use --password-stdin or the prompt."
                );
            }
        }
        foreach ($command->params() as $param) {
            if ($param->isRequired() && null === $args->value($param->name())) {
                throw Failure::usage('MISSING_PARAMETER', "Parameter '{$param->name()}' is required: {$param->syntax()}.");
            }
        }
    }

    private function writeHelp(Command $command)
    {
        if ('json' === $this->format) {
            $this->console->out(Json::encode(Envelope::build(
                'help',
                null,
                '',
                ['commands' => [Usage::manifestEntry($command)]],
                [],
                [],
                $this->start
            )) . "\n");

            return;
        }
        $this->console->out(Usage::command($command));
    }

    /**
     * Write the result and set the exit code.
     *
     * @param array<string, mixed> $data
     * @param Failure|null $failure
     */
    private function finish(array $data, $failure)
    {
        $this->state = self::STATE_DONE;
        $errors = null === $this->ctx ? [] : $this->ctx->errors();
        $this->exitCode = null === $this->ctx ? ExitCode::SUCCESS : $this->ctx->exitCode();
        if (null !== $failure) {
            Log::error((null !== $this->command ? $this->command->name() . ': ' : '') . $failure->getMessage());
            $errors[] = Envelope::error($failure);
            $this->exitCode = $failure->exitCode();
        }
        $warnings = null === $this->ctx ? [] : $this->ctx->warnings();

        if ('json' === $this->format) {
            $instance = null;
            if (null !== $this->ctx && null !== $this->ctx->instanceIfResolved()) {
                $instance = $this->ctx->instanceIfResolved()->toArray();
                $instance['entity'] = $this->ctx->entity();
            }
            $envelope = Envelope::build(
                null === $this->command ? '' : $this->command->name(),
                $instance,
                null === $this->ctx ? '' : $this->ctx->actingUser(),
                $data,
                $warnings,
                $errors,
                $this->start
            );
            try {
                $this->console->out(Json::encode($envelope) . "\n");
            } catch (Failure $encoding) {
                $this->console->err('Error: ' . $encoding->getMessage() . "\n");
                $this->exitCode = ExitCode::FAILURE;
            }

            return;
        }

        if ('prometheus' === $this->format || 'nagios' === $this->format) {
            if (null === $failure && null !== $this->command) {
                list($output, $code) = $this->command->renderFormat($this->format, $data);
                $this->console->out($output);
                $this->exitCode = $code;

                return;
            }
            if ('nagios' === $this->format) {
                $message = null === $failure ? 'no result' : $failure->getMessage();
                $this->console->out('DOLIBARR UNKNOWN - ' . Text::line($message) . "\n");
                $this->exitCode = ExitCode::NAGIOS_UNKNOWN;

                return;
            }
        }

        if (null !== $failure) {
            $this->console->err('Error: ' . Text::safe($failure->getMessage()) . "\n");
            if (ExitCode::USAGE === $failure->exitCode() && null !== $this->command && !$this->command instanceof HelpCommand) {
                $this->console->err("Run '" . Usage::PROGRAM . ' ' . $this->command->name() . " --help' for the usage.\n");
            }

            return;
        }
        if (isset($data['dry_run']) && true === $data['dry_run'] && isset($data['actions']) && is_array($data['actions'])) {
            $this->console->out("Dry run, nothing written. Actions:\n  - " . implode("\n  - ", array_map([Text::class, 'line'], $data['actions'])) . "\n");
        } elseif (null !== $this->command) {
            $this->console->out($this->command->renderText($data));
        }
        foreach ($errors as $error) {
            $this->console->err('Error: ' . Text::safe($error['message']) . "\n");
        }
    }

    private function internalError(\Throwable $e): Failure
    {
        Log::error('uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if ('1' === getenv('DOLICLI_DEBUG')) {
            $this->console->err($e->getTraceAsString() . "\n");
        }

        return Failure::operation('INTERNAL_ERROR', get_class($e) . ': ' . $e->getMessage());
    }

    /**
     * Commands working on the data of an instance: with Dolibarr loaded, or on an instance
     * whose documents/ is read in conf.php (switch of files, restore).
     */
    private function usesInstanceData(Command $command): bool
    {
        return $this->dolibarrLoaded || ($command->needsInstance() && !$command->needsDolibarr());
    }

    private function releaseLock()
    {
        if (null !== $this->lock) {
            $this->lock->release();
            $this->lock = null;
        }
    }

    /**
     * One line in the journal of the instance for every write command actually run.
     */
    private function journal()
    {
        if ('' === $this->journalRoot || null === $this->command || null === $this->ctx) {
            return;
        }
        $journal = Journal::forDataRoot($this->journalRoot);
        $this->journalRoot = '';
        $journal->record(
            $this->command->name(),
            $this->ctx->args()->describe($this->command->sensitiveParams()),
            $this->ctx->actingUser(),
            $this->exitCode
        );
    }
}
