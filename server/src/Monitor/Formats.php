<?php

namespace Dolicli\Monitor;

use Dolicli\Cli\ExitCode;
use Dolicli\Cli\Text;

/**
 * Output formats of a state file: Prometheus text exposition (textfile collector of
 * node_exporter), Nagios plugin, text.
 */
final class Formats
{
    const STATUS_CODES = ['ok' => 0, 'warning' => 1, 'critical' => 2, 'unknown' => 3];

    /**
     * Numeric metrics: check => [metric name, help, unit suffix of the Nagios perfdata].
     */
    const METRICS = [
        'database.reachable' => ['dolibarr_database_reachable', 'Database reachable (1) or not (0).', ''],
        'dolibarr.upgrade_pending' => ['dolibarr_upgrade_pending', 'Files newer than the database (1) or not (0).', ''],
        'maintenance.enabled' => ['dolibarr_maintenance_enabled', 'Maintenance mode on (1) or off (0).', ''],
        'sessions.count' => ['dolibarr_sessions', 'Estimated number of active sessions.', ''],
        'users.active' => ['dolibarr_users_active', 'Number of active users of the entity.', ''],
        'cron.failed' => ['dolibarr_cron_failed', 'Active scheduled jobs whose last run failed.', ''],
        'cron.stale' => ['dolibarr_cron_stale', 'Active scheduled jobs late.', ''],
        'cron.stuck' => ['dolibarr_cron_stuck', 'Scheduled jobs running for too long.', ''],
        'backup.age' => ['dolibarr_backup_age_seconds', 'Age of the most recent backup.', 's'],
        'storage.free_bytes' => ['dolibarr_storage_free_bytes', 'Free space of the fullest partition of documents/ and htdocs.', 'B'],
        'storage.documents_bytes' => ['dolibarr_storage_documents_bytes', 'Size of documents/.', 'B'],
        'storage.database_bytes' => ['dolibarr_storage_database_bytes', 'Size of the database.', 'B'],
        'filecheck.modified' => ['dolibarr_filecheck_modified', 'Files modified against the signature.', ''],
        'filecheck.missing' => ['dolibarr_filecheck_missing', 'Files missing against the signature.', ''],
        'filecheck.added' => ['dolibarr_filecheck_added', 'Files not in the signature.', ''],
        'security.install_lock' => ['dolibarr_install_lock', 'install.lock present (1) or not (0).', ''],
        'security.login_failures_24h' => ['dolibarr_login_failures_24h', 'Login failures in the last 24 hours.', ''],
        'tls.expiry_seconds' => ['dolibarr_tls_expiry_seconds', 'Seconds before the certificate expires.', 's'],
        'collector.age' => ['dolibarr_collector_age_seconds', 'Seconds since the last collection.', 's'],
    ];

    /**
     * @param array<string, mixed> $document State file with freshness applied
     */
    public static function prometheus(array $document): string
    {
        $instance = isset($document['instance']['id']) ? (string) $document['instance']['id'] : '';
        $label = 'dolibarr_instance="' . self::escape($instance) . '"';
        $checks = isset($document['checks']) && is_array($document['checks']) ? $document['checks'] : [];
        $value = function ($id) use ($checks) {
            return isset($checks[$id]['value']) ? $checks[$id]['value'] : '';
        };

        $out = "# HELP dolibarr_info Dolibarr instance information.\n# TYPE dolibarr_info gauge\n"
            . 'dolibarr_info{' . $label . ',version="' . self::escape((string) $value('dolibarr.version')) . '",php_cli="'
            . self::escape((string) $value('php.cli_version')) . "\"} 1\n";

        $out .= "# HELP dolibarr_status Global status of the instance: 0 ok, 1 warning, 2 critical, 3 unknown.\n# TYPE dolibarr_status gauge\n";
        $status = isset($document['status']) ? (string) $document['status'] : 'unknown';
        $out .= 'dolibarr_status{' . $label . '} ' . (isset(self::STATUS_CODES[$status]) ? self::STATUS_CODES[$status] : 3) . "\n";

        $out .= "# HELP dolibarr_check_status Check status: 0 ok, 1 warning, 2 critical, 3 unknown.\n# TYPE dolibarr_check_status gauge\n";
        foreach ($checks as $id => $check) {
            if (!is_array($check) || 'skipped' === $check['status']) {
                continue;
            }
            $code = isset(self::STATUS_CODES[$check['status']]) ? self::STATUS_CODES[$check['status']] : 3;
            $out .= 'dolibarr_check_status{' . $label . ',check="' . self::escape((string) $id) . '"} ' . $code . "\n";
        }

        foreach (self::METRICS as $id => $metric) {
            if (!isset($checks[$id]) || 'skipped' === $checks[$id]['status']) {
                continue;
            }
            $number = self::number(isset($checks[$id]['value']) ? $checks[$id]['value'] : null);
            if (null === $number) {
                continue;
            }
            $out .= "# HELP {$metric[0]} {$metric[1]}\n# TYPE {$metric[0]} gauge\n{$metric[0]}{" . $label . '} ' . $number . "\n";
        }

        $out .= "# HELP dolibarr_collect_timestamp_seconds Time of the last collection.\n# TYPE dolibarr_collect_timestamp_seconds gauge\n"
            . 'dolibarr_collect_timestamp_seconds{' . $label . '} ' . (isset($document['generated_at_unix']) ? (int) $document['generated_at_unix'] : 0) . "\n";

        return $out;
    }

    /**
     * Nagios plugin output and exit code (0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN).
     *
     * @param array<string, mixed> $document State file with freshness applied
     * @param string $only Restrict to one check
     * @return array{0: string, 1: int}
     */
    public static function nagios(array $document, string $only = ''): array
    {
        $instance = isset($document['instance']['id']) ? (string) $document['instance']['id'] : '?';
        $checks = isset($document['checks']) && is_array($document['checks']) ? $document['checks'] : [];
        if ('' !== $only) {
            if (!isset($checks[$only])) {
                return ["DOLIBARR UNKNOWN - {$instance}: check {$only} not in the state file\n", ExitCode::NAGIOS_UNKNOWN];
            }
            $checks = [$only => $checks[$only]];
        }
        $status = State::overall($checks);
        $problems = [];
        foreach ($checks as $id => $check) {
            if (in_array($check['status'], ['warning', 'critical', 'unknown'], true)) {
                $problems[] = $id . ' ' . $check['message'];
            }
        }
        $perf = [];
        foreach (self::METRICS as $id => $metric) {
            if (!isset($checks[$id]) || 'skipped' === $checks[$id]['status']) {
                continue;
            }
            $number = self::number(isset($checks[$id]['value']) ? $checks[$id]['value'] : null);
            if (null === $number) {
                continue;
            }
            $thresholds = isset($checks[$id]['thresholds']) && is_array($checks[$id]['thresholds']) ? $checks[$id]['thresholds'] : [];
            $perf[] = str_replace(['dolibarr_', '_seconds', '_bytes'], '', $metric[0]) . '=' . $number . $metric[2]
                . ';' . (isset($thresholds['warning']) ? $thresholds['warning'] : '') . ';' . (isset($thresholds['critical']) ? $thresholds['critical'] : '');
        }
        $summary = [] === $problems ? 'all checks ok' : implode(', ', $problems);
        $line = 'DOLIBARR ' . strtoupper($status) . ' - ' . $instance . ': ' . Text::line($summary);

        return [$line . ([] === $perf ? '' : ' | ' . implode(' ', $perf)) . "\n", self::STATUS_CODES[$status]];
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function text(array $document): string
    {
        $rows = [];
        $checks = isset($document['checks']) && is_array($document['checks']) ? $document['checks'] : [];
        foreach ($checks as $id => $check) {
            $rows[] = [$check['status'], $id, Text::scalar($check['value']), $check['message']];
        }
        $instance = isset($document['instance']['id']) ? $document['instance']['id'] : '?';

        return "Instance {$instance}: " . strtoupper((string) $document['status']) . ' (collected ' . $document['generated_at'] . ")\n\n"
            . Text::table(['Status', 'Check', 'Value', 'Message'], $rows);
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private static function number($value)
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
