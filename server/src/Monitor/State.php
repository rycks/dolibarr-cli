<?php

namespace Dolicli\Monitor;

use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;
use Dolicli\System\Files;
use Dolicli\System\Log;
use Dolicli\Version;

/**
 * State file of an instance (schema/state.schema.json): checks indexed by identifier,
 * each with its own collection date. Fast and slow collections merge check by check.
 */
final class State
{
    const STATUSES = ['ok', 'warning', 'critical', 'unknown', 'skipped'];

    /** Order of gravity for the global status (skipped never counts). */
    const GRAVITY = ['ok' => 0, 'warning' => 1, 'unknown' => 2, 'critical' => 3];

    const FAST_INTERVAL = 300;

    const SLOW_MAX_AGE = 93600;

    /**
     * @param array<string, mixed>|null $thresholds
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function check(string $status, $value, string $message, string $frequency, $thresholds = null): array
    {
        $check = ['status' => $status, 'value' => $value, 'message' => $message, 'collected_at' => date('c'), 'frequency' => $frequency];
        if (null !== $thresholds && [] !== $thresholds) {
            $check['thresholds'] = $thresholds;
        }

        return $check;
    }

    /**
     * @param array<string, mixed> $instance id, htdocs, url
     * @param array<string, array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    public static function document(array $instance, array $checks): array
    {
        ksort($checks);

        return [
            'schema_version' => Version::STATE_SCHEMA,
            'dolicli_version' => Version::VERSION,
            'generated_at' => date('c'),
            'generated_at_unix' => time(),
            'instance' => $instance,
            'status' => self::overall($checks),
            'checks' => Json::object($checks),
        ];
    }

    /**
     * Worst status of the checks, skipped ones excluded: critical > unknown > warning > ok.
     *
     * @param array<string, array<string, mixed>> $checks
     */
    public static function overall(array $checks): string
    {
        $worst = 'ok';
        foreach ($checks as $check) {
            $status = isset($check['status']) ? (string) $check['status'] : 'unknown';
            if (isset(self::GRAVITY[$status]) && self::GRAVITY[$status] > self::GRAVITY[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * Merge freshly collected checks into the file, under its lock, and write it atomically.
     *
     * @param array<string, mixed> $instance
     * @param array<string, array<string, mixed>> $checks
     * @return array<string, mixed> The document written
     */
    public static function merge(string $file, array $instance, array $checks, int $mode = 0640): array
    {
        Files::ensureDir(dirname($file), 02750);
        $lock = @fopen(substr($file, 0, -5) . '.lock', 'c');
        if (false === $lock) {
            Log::error("monitor: cannot open the lock of {$file}");
            throw Failure::operation('STATE_UNWRITABLE', "Cannot write next to {$file}.");
        }
        try {
            flock($lock, LOCK_EX);
            $current = self::read($file);
            $merged = isset($current['checks']) && is_array($current['checks']) ? $current['checks'] : [];
            foreach ($checks as $id => $check) {
                $merged[$id] = $check;
            }
            $document = self::document($instance, $merged);
            Files::writeAtomic($file, Json::encode($document) . "\n", $mode);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $document;
    }

    /**
     * @return array<string, mixed> Empty when absent or unreadable
     */
    public static function read(string $file): array
    {
        $content = @file_get_contents($file);
        if (!is_string($content)) {
            return [];
        }
        $data = Json::decode($content);

        return is_array($data) ? $data : [];
    }

    /**
     * Freshness at read time: a fast check older than three times the fast interval, or a slow
     * check older than 26 h, becomes unknown. collector.age is computed, never stored.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function withFreshness(array $document, int $now, int $fastInterval = self::FAST_INTERVAL): array
    {
        $checks = isset($document['checks']) && is_array($document['checks']) ? $document['checks'] : [];
        foreach ($checks as $id => $check) {
            if (!is_array($check) || !isset($check['collected_at'])) {
                continue;
            }
            $age = $now - (int) strtotime((string) $check['collected_at']);
            $limit = (isset($check['frequency']) && 'slow' === $check['frequency']) ? self::SLOW_MAX_AGE : 3 * $fastInterval;
            if ($age > $limit && 'skipped' !== $check['status']) {
                $checks[$id]['status'] = 'unknown';
                $checks[$id]['message'] = 'Stale: collected ' . $age . ' s ago (' . (string) $check['message'] . ')';
            }
        }
        $generated = isset($document['generated_at_unix']) ? (int) $document['generated_at_unix'] : 0;
        $age = $now - $generated;
        $checks['collector.age'] = [
            'status' => $age > 3 * $fastInterval ? 'critical' : 'ok',
            'value' => $age,
            'thresholds' => ['critical' => 3 * $fastInterval],
            'message' => 'Last collection ' . $age . ' s ago',
            'collected_at' => date('c', $now),
            'frequency' => 'computed',
        ];
        ksort($checks);
        $document['checks'] = $checks;
        $document['status'] = self::overall($checks);

        return $document;
    }
}
