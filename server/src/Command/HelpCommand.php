<?php

namespace Dolicli\Command;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\ExitCode;
use Dolicli\Cli\Failure;
use Dolicli\Cli\GlobalOptions;
use Dolicli\Cli\Registry;
use Dolicli\Cli\RegistryAware;
use Dolicli\Cli\Usage;
use Dolicli\Version;

/**
 * General help, help of a group or command, and the manifest (--format=json).
 */
final class HelpCommand extends Command implements RegistryAware
{
    /** @var Registry|null */
    private $registry;

    public function setRegistry(Registry $registry)
    {
        $this->registry = $registry;
    }

    public function name(): string
    {
        return 'help';
    }

    public function summary(): string
    {
        return 'List the commands; with --format=json, the manifest read by the pilot';
    }

    public function needsDolibarr(): bool
    {
        return false;
    }

    public function allowedAsRoot(): bool
    {
        return true;
    }

    public function help(): string
    {
        return "dolicli help <group> [<command>] gives the help of a group or a command.\n"
            . "The manifest (--format=json) describes every command: category (read, write,\n"
            . 'destructive), need of Dolibarr, parameters and sensitive parameters, batch permission.';
    }

    public function execute(Context $ctx): array
    {
        $registry = $this->registry;
        if (null === $registry) {
            throw Failure::operation('NO_REGISTRY', 'Command list unavailable.');
        }
        $topic = $ctx->args()->words();
        $commands = [] === $topic ? $registry->all() : $registry->withPrefix($topic);
        if ([] === $commands) {
            throw Failure::usage('UNKNOWN_COMMAND', "Unknown command '" . implode(' ', $topic) . "'.");
        }
        $exact = [] === $topic ? null : $registry->find($topic);
        $isCommand = null !== $exact && $exact[1] === count($topic);
        if ($isCommand) {
            $commands = [$exact[0]->name() => $exact[0]];
        }

        $entries = [];
        foreach ($commands as $command) {
            $entries[] = Usage::manifestEntry($command);
        }
        $global = [];
        foreach (GlobalOptions::all() as $param) {
            $global[] = $param->toArray();
        }
        $exitCodes = [];
        foreach (ExitCode::descriptions() as $code => $description) {
            $exitCodes[] = ['code' => $code, 'description' => $description];
        }

        return [
            'topic' => implode(' ', $topic),
            'is_command' => $isCommand,
            'dolicli_version' => Version::VERSION,
            'envelope_schema' => Version::ENVELOPE_SCHEMA,
            'state_schema' => Version::STATE_SCHEMA,
            'commands' => $entries,
            'global_options' => $global,
            'exit_codes' => $exitCodes,
        ];
    }

    public function renderText(array $data): string
    {
        $registry = $this->registry;
        if (null === $registry) {
            return '';
        }
        $commands = [];
        foreach ($data['commands'] as $entry) {
            $commands[$entry['name']] = $registry->all()[$entry['name']];
        }
        if ($data['is_command']) {
            return Usage::command(reset($commands));
        }
        if ('' !== $data['topic']) {
            return Usage::overview($commands, 'Commands of ' . $data['topic'] . ':') . "\nDetailed help: " . Usage::PROGRAM . " <command> --help\n";
        }

        return 'dolicli ' . Version::VERSION . " - Dolibarr administration from the command line\n\n"
            . Usage::overview($commands, 'Commands:') . "\n" . Usage::globalOptions();
    }
}
