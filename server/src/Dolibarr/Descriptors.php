<?php

namespace Dolicli\Dolibarr;

use Dolicli\Cli\Text;
use Dolicli\System\Log;

/**
 * Watch over the loading of the module descriptors (core/modules/modXxx.class.php).
 *
 * A descriptor of an external module may print, call exit() or stop PHP with a fatal error while
 * it is included or built. The file being loaded is recorded first, so that the shutdown handler
 * can name it, and what it prints goes to an output buffer, never to stdout: the envelope stays
 * valid JSON. Also reads the descriptors a relaunched module list must leave out.
 */
final class Descriptors
{
    /**
     * Environment variable through which a relaunched module list receives the descriptors to
     * leave out: JSON list of {"class", "file", "reason"}. Set only by dolicli for its own child.
     */
    const SKIP_VARIABLE = 'DOLICLI_SKIP_DESCRIPTORS';

    /** Most descriptors left out by successive relaunches. */
    const MAX_SKIPPED = 5;

    /** Last lines of output kept in an error. */
    const OUTPUT_LINES = 10;

    /** @var array{file: string, module: string, level: int}|null */
    private static $current;

    /**
     * Before the inclusion (or the construction) of a descriptor.
     */
    public static function begin(string $file, string $module)
    {
        ob_start();
        self::$current = ['file' => $file, 'module' => $module, 'level' => ob_get_level()];
    }

    /**
     * After a load that returned: what the descriptor printed goes to the log only.
     */
    public static function end()
    {
        self::close();
    }

    /**
     * Descriptor whose load did not return (exit(), fatal error, exception), null when no
     * descriptor is being loaded: its file, its class and the last lines it printed, cleaned.
     * Ends the watch and throws the buffered output away.
     *
     * @return array{file: string, module: string, output: array<int, string>}|null
     */
    public static function interrupted()
    {
        $closed = self::close();
        if (null === $closed) {
            return null;
        }

        return ['file' => $closed['file'], 'module' => $closed['module'], 'output' => self::lastLines($closed['output'])];
    }

    /**
     * Core descriptors (htdocs/core/modules) are never left out. Unknown when Dolibarr is not
     * loaded: treated as core.
     */
    public static function isCore(string $file): bool
    {
        if (!defined('DOL_DOCUMENT_ROOT')) {
            return true;
        }
        $core = realpath(DOL_DOCUMENT_ROOT . '/core/modules');
        $dir = realpath(dirname($file));

        return false === $core || false === $dir || $core === $dir;
    }

    /**
     * Descriptors to leave out, given by the dolicli process that relaunched this one. Empty for
     * a normal run; an invalid value is ignored with a log line.
     *
     * @return array<int, array{class: string, file: string, reason: string}>
     */
    public static function skippedFromEnvironment(): array
    {
        $raw = getenv(self::SKIP_VARIABLE);
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            Log::warning(self::SKIP_VARIABLE . ' ignored: not a JSON list');

            return [];
        }
        $skipped = [];
        foreach ($list as $entry) {
            $valid = is_array($entry) && isset($entry['class'], $entry['file'], $entry['reason'])
                && is_string($entry['class']) && is_string($entry['file']) && is_string($entry['reason']);
            if (!$valid) {
                Log::warning(self::SKIP_VARIABLE . ' ignored: every entry needs the strings class, file and reason');

                return [];
            }
            $skipped[] = ['class' => $entry['class'], 'file' => $entry['file'], 'reason' => $entry['reason']];
        }

        return array_slice($skipped, 0, self::MAX_SKIPPED);
    }

    /**
     * @return array{file: string, module: string, output: string}|null
     */
    private static function close()
    {
        if (null === self::$current) {
            return null;
        }
        $current = self::$current;
        self::$current = null;
        $output = '';
        while (ob_get_level() >= $current['level']) {
            $chunk = ob_get_clean();
            if (false === $chunk) {
                Log::warning('cannot remove the output buffer of module descriptor ' . $current['file']);
                break;
            }
            $output = $chunk . $output;
        }
        if ('' !== trim($output)) {
            Log::warning('output of module descriptor ' . $current['file'] . ': ' . substr(trim($output), 0, 2000));
        }

        return ['file' => $current['file'], 'module' => $current['module'], 'output' => $output];
    }

    /**
     * Last non-empty lines of an output, without HTML tags nor control characters.
     *
     * @return array<int, string>
     */
    private static function lastLines(string $output): array
    {
        $text = html_entity_decode(strip_tags(str_replace(["\r\n", "\r"], "\n", $output)), ENT_QUOTES);
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(Text::line($line));
            if ('' !== $line) {
                $short = preg_replace('/^(.{300}).+$/us', '$1...', $line);
                $lines[] = is_string($short) ? $short : $line;
            }
        }

        return array_slice($lines, -self::OUTPUT_LINES);
    }
}
