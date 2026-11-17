<?php

namespace Dolicli\Php;

use Dolicli\System\Process;

/**
 * PHP CLI binaries installed on the server (distribution packages, Remi, Plesk, cPanel,
 * CloudLinux), with their version and the extensions useful to Dolibarr.
 */
final class Binaries
{
    const PATTERNS = [
        '/usr/bin/php*',
        '/usr/local/bin/php*',
        '/opt/remi/php*/root/usr/bin/php',
        '/opt/plesk/php/*/bin/php',
        '/opt/cpanel/ea-php*/root/usr/bin/php',
        '/opt/alt/php*/usr/bin/php',
        '/usr/local/php*/bin/php',
    ];

    const USEFUL_EXTENSIONS = [
        'json', 'mysqli', 'pgsql', 'sqlite3', 'mbstring', 'intl', 'gd', 'zip', 'xml', 'simplexml',
        'curl', 'soap', 'imap', 'calendar', 'posix', 'openssl', 'ldap',
    ];

    /** @var array<int, string> */
    private $patterns;

    /**
     * @param array<int, string> $patterns
     */
    public function __construct(array $patterns = self::PATTERNS)
    {
        $this->patterns = $patterns;
    }

    /**
     * @return array<int, array{path: string, version: string, extensions: array<int, string>, missing: array<int, string>}>
     */
    public function all(): array
    {
        $found = [];
        $seen = [];
        foreach ($this->patterns as $pattern) {
            $paths = glob($pattern);
            foreach (is_array($paths) ? $paths : [] as $path) {
                if (1 !== preg_match('/(^|\/)php(\d+(\.\d+)?)?$/', $path) || !is_file($path) || !is_executable($path)) {
                    continue;
                }
                $real = realpath($path);
                if (false === $real || isset($seen[$real])) {
                    continue;
                }
                $seen[$real] = true;
                $probe = self::probe($path);
                if (null !== $probe) {
                    $found[] = $probe;
                }
            }
        }
        usort($found, function ($a, $b) {
            return version_compare($a['version'], $b['version']);
        });

        return $found;
    }

    /**
     * Version and extensions of one binary, null when it does not run.
     *
     * @return array{path: string, version: string, extensions: array<int, string>, missing: array<int, string>}|null
     */
    public static function probe(string $path)
    {
        $process = new Process();
        $code = 'echo PHP_VERSION, "\n", implode(",", array_map("strtolower", get_loaded_extensions())), "\n";';
        if (0 !== $process->run([$path, '-r', $code])) {
            return null;
        }
        $lines = explode("\n", trim($process->stdout()));
        if (count($lines) < 2 || 1 !== preg_match('/^\d+\.\d+\.\d+/', $lines[0])) {
            return null;
        }
        $loaded = explode(',', $lines[1]);
        $useful = array_values(array_intersect(self::USEFUL_EXTENSIONS, $loaded));

        return [
            'path' => $path,
            'version' => $lines[0],
            'extensions' => $useful,
            'missing' => array_values(array_diff(self::USEFUL_EXTENSIONS, $loaded)),
        ];
    }
}
