<?php

namespace Dolicli\Cli;

/**
 * Standard input of the ephemeral mode, read by `php --` as a script: the arguments in
 * $DOLICLI_ARGV (program name first), the files sent along in $DOLICLI_FILES (base64), then the
 * body as the nowdoc string $DOLICLI_SOURCE, run with eval().
 *
 * PHP reads the whole standard input before it compiles it: once running, a body sent as plain
 * code has no way to get its own text back. As a string, it costs one more scan of the text and
 * keeps the body available to run itself again in a child process (module list without a module
 * descriptor that stopped PHP). Built by the pilot and by the body itself.
 */
final class Stream
{
    /** End of the nowdoc, split so that the body, which holds this class, never contains it. */
    const END = 'DOLICLI_BODY' . '_END_OF_SOURCE';

    /**
     * @param array<int, string> $argv Arguments, program name first
     * @param string $body Built body (dolicli.php)
     * @param array<string, string> $files Contents sent along, base64-encoded in the stream
     */
    public static function build(array $argv, string $body, array $files = []): string
    {
        if (false !== strpos($body, self::END)) {
            throw new Failure('INVALID_BODY', 'The dolicli body contains ' . self::END . ', the end marker of the stream: it cannot be sent.');
        }
        $stream = '<?php namespace { $DOLICLI_ARGV = ' . var_export(array_values($argv), true) . ';';
        if ([] !== $files) {
            $stream .= ' $DOLICLI_FILES = ' . var_export(array_map('base64_encode', $files), true) . ';';
        }

        // The lines after eval() run only when the body did not compile and eval() returned (PHP 5).
        return $stream . " \$DOLICLI_SOURCE = <<<'" . self::END . "'\n" . $body . "\n" . self::END . ";\n"
            . "eval('?>' . \$DOLICLI_SOURCE);\n"
            . "file_put_contents('php://stderr', 'dolicli needs PHP 7.0 or later, this is PHP ' . PHP_VERSION . \"\\n\");\n"
            . "exit(3);\n}\n";
    }
}
