<?php

namespace Dolicli\Monitor;

use Dolicli\System\Log;
use Dolicli\System\Paths;

/**
 * Thresholds of the checks: documented defaults, overridden in /etc/dolicli/thresholds.ini,
 * section [default] then one section per instance, keys <check>.warning and <check>.critical.
 */
final class Thresholds
{
    /**
     * check => [direction, warning, critical]: 'above' (higher is worse) or 'below' (lower is worse).
     * null: no threshold at that level.
     */
    const DEFAULTS = [
        'backup.age' => ['above', 129600, 259200],
        'storage.free_bytes' => ['below', 5368709120, 1073741824],
        'cron.failed' => ['above', 1, null],
        'cron.stale' => ['above', 1, null],
        'cron.stuck' => ['above', 1, null],
        'filecheck.modified' => ['above', 1, null],
        'filecheck.missing' => ['above', 1, null],
        'filecheck.added' => ['above', 1, null],
        'security.login_failures_24h' => ['above', 20, 100],
        'tls.expiry_seconds' => ['below', 1814400, 604800],
    ];

    /** @var array<string, array{0: string, 1: int|float|null, 2: int|float|null}> */
    private $values;

    /** @var array<string, string> */
    private $options;

    /**
     * @param array<string, array{0: string, 1: int|float|null, 2: int|float|null}> $values
     * @param array<string, string> $options
     */
    private function __construct(array $values, array $options)
    {
        $this->values = $values;
        $this->options = $options;
    }

    public static function forInstance(string $instanceId, string $file = ''): self
    {
        $values = self::DEFAULTS;
        $options = [];
        $file = '' === $file ? Paths::thresholdsIni() : $file;
        if (is_file($file)) {
            $ini = @parse_ini_file($file, true, INI_SCANNER_RAW);
            if (!is_array($ini)) {
                Log::error("thresholds: cannot parse {$file}, defaults used");
            } else {
                foreach (['default', $instanceId] as $section) {
                    if (!isset($ini[$section]) || !is_array($ini[$section])) {
                        continue;
                    }
                    foreach ($ini[$section] as $key => $raw) {
                        $key = (string) $key;
                        $raw = trim((string) $raw);
                        if (1 === preg_match('/^(.+)\.(warning|critical)$/', $key, $m) && isset($values[$m[1]]) && is_numeric($raw)) {
                            $values[$m[1]][('warning' === $m[2]) ? 1 : 2] = 0 + $raw;
                        } else {
                            $options[$key] = $raw;
                        }
                    }
                }
            }
        }

        return new self($values, $options);
    }

    /**
     * Option of thresholds.ini that is not a threshold (tls.enabled, fast_interval...).
     */
    public function option(string $name, string $default = ''): string
    {
        return isset($this->options[$name]) ? $this->options[$name] : $default;
    }

    /**
     * Status of a numeric value and the thresholds applied.
     *
     * @param int|float $value
     * @return array{0: string, 1: array<string, int|float>|null}
     */
    public function evaluate(string $check, $value): array
    {
        if (!isset($this->values[$check])) {
            return ['ok', null];
        }
        list($direction, $warning, $critical) = $this->values[$check];
        $applied = [];
        if (null !== $warning) {
            $applied['warning'] = $warning;
        }
        if (null !== $critical) {
            $applied['critical'] = $critical;
        }
        $worse = function ($limit) use ($direction, $value) {
            return 'above' === $direction ? $value >= $limit : $value <= $limit;
        };
        if (null !== $critical && $worse($critical)) {
            return ['critical', $applied];
        }
        if (null !== $warning && $worse($warning)) {
            return ['warning', $applied];
        }

        return ['ok', $applied];
    }
}
