<?php

namespace Dolicli\Cli;

/**
 * Help texts: general help, group help, detailed help of a command, manifest.
 */
final class Usage
{
    const PROGRAM = 'dolicli';

    public static function command(Command $command): string
    {
        $required = [];
        $optional = [];
        foreach ($command->params() as $param) {
            if ($param->isRequired()) {
                $required[] = $param->syntax();
            } else {
                $optional[] = '[' . $param->syntax() . ']';
            }
        }

        $out = $command->summary() . "\n\nUsage:\n  " . trim(self::PROGRAM . ' ' . $command->name() . ' ' . implode(' ', array_merge($required, $optional))) . "\n";

        if ([] !== $command->params()) {
            $out .= "\nParameters and options:\n" . self::paramLines($command->params());
        }

        $out .= "\nCategory: " . $command->category();
        if ($command->isWrite()) {
            $out .= ' (supports --dry-run' . ($command->allowedInBatch() ? '' : ', not allowed in batch') . ')';
        }
        $out .= $command->needsDolibarr() ? ", loads Dolibarr\n" : ", does not load Dolibarr\n";

        $help = trim($command->help());
        if ('' !== $help) {
            $out .= "\n" . $help . "\n";
        }
        if ([] !== $command->examples()) {
            $out .= "\nExamples:\n";
            foreach ($command->examples() as $example) {
                $out .= '  ' . self::PROGRAM . ' ' . $example . "\n";
            }
        }
        $out .= "\nGlobal options: " . self::PROGRAM . " help\n";

        return $out;
    }

    /**
     * @param array<string, Command> $commands
     */
    public static function overview(array $commands, string $title): string
    {
        $out = $title . "\n";
        $group = '';
        $width = 0;
        foreach ($commands as $name => $command) {
            $width = max($width, strlen($name));
        }
        foreach ($commands as $name => $command) {
            if ($command->group() !== $group) {
                $group = $command->group();
                $out .= "\n  " . strtoupper($group) . "\n";
            }
            $out .= '    ' . str_pad($name, $width + 2) . $command->summary() . "\n";
        }

        return $out;
    }

    public static function globalOptions(): string
    {
        $out = "Usage:\n"
            . '  ' . self::PROGRAM . " <group> <command> [key=value ...] [--option ...]\n"
            . '  ' . self::PROGRAM . " <group> <command> --help   detailed help of a command\n"
            . '  ' . self::PROGRAM . " <group>                    commands of a group\n"
            . '  ' . self::PROGRAM . " help --format=json         manifest of all commands\n\n"
            . "Global options:\n" . self::paramLines(GlobalOptions::all())
            . "\nEnvironment:\n"
            . "  DOLPATH=/path/to/htdocs   Dolibarr to work on when --instance and --dolpath are absent\n"
            . "  DOLICLI_DEBUG=1           Print the stack trace of unexpected errors\n\n"
            . "Exit codes:\n";
        foreach (ExitCode::descriptions() as $code => $description) {
            $out .= '  ' . $code . '  ' . $description . "\n";
        }

        return $out;
    }

    /**
     * @param array<int, Param> $params
     */
    private static function paramLines(array $params): string
    {
        $width = 0;
        foreach ($params as $param) {
            $width = max($width, strlen($param->syntax()));
        }
        $out = '';
        foreach ($params as $param) {
            $flags = [];
            if ($param->isRequired()) {
                $flags[] = 'required';
            }
            if ($param->isSensitive()) {
                $flags[] = 'secret: --password-stdin or prompt, never on the command line';
            }
            $out .= '  ' . str_pad($param->syntax(), $width + 2) . $param->description()
                . ([] === $flags ? '' : ' (' . implode(', ', $flags) . ')') . "\n";
        }

        return $out;
    }

    /**
     * Description of a command for the manifest.
     *
     * @return array<string, mixed>
     */
    public static function manifestEntry(Command $command): array
    {
        $params = [];
        foreach ($command->params() as $param) {
            $params[] = $param->toArray();
        }

        return [
            'name' => $command->name(),
            'group' => $command->group(),
            'summary' => $command->summary(),
            'category' => $command->category(),
            'needs_dolibarr' => $command->needsDolibarr(),
            'needs_instance' => $command->needsInstance(),
            'batch' => $command->allowedInBatch(),
            'dry_run' => $command->isWrite(),
            'formats' => array_merge(['text', 'json'], $command->extraFormats()),
            'params' => $params,
            'sensitive_params' => $command->sensitiveParams(),
        ];
    }
}
