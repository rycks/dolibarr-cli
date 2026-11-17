<?php

declare(strict_types=1);

namespace Dolifleet\Console\Data;

use Dolifleet\Console\Lang\Translator;

/**
 * Monitoring checks of dolicli seen by the console: a label and a problem text in the language
 * of the console, built from the name and the value of the check (never by translating the
 * English message, which stays visible in the details), and the key that fixes it.
 */
final class Checks
{
    public const GRAVITY = ['ok' => 0, 'skipped' => 0, 'warning' => 1, 'unknown' => 2, 'critical' => 3];

    /** @var list<string> Checks the console knows by name: they have a label in every language. */
    public const KNOWN = [
        'collector.age', 'database.reachable', 'dolibarr.version', 'dolibarr.db_version', 'dolibarr.upgrade_pending',
        'php.cli_version', 'php.web_version', 'php.compatibility', 'maintenance.enabled', 'sessions.count',
        'users.active', 'cron.failed', 'cron.stale', 'cron.stuck', 'backup.age', 'storage.free_bytes',
        'storage.documents_bytes', 'storage.database_bytes', 'filecheck.modified', 'filecheck.missing',
        'filecheck.added', 'modules.external', 'security.install_lock', 'security.conf_permissions',
        'security.prod_mode', 'security.login_failures_24h', 'dolicli.operation', 'tls.expiry_seconds',
    ];

    public function __construct(private readonly Translator $t)
    {
    }

    public function label(string $check): string
    {
        return in_array($check, self::KNOWN, true) ? $this->t->t('check.'.$check) : $check;
    }

    /**
     * Problems of a state document, most serious first.
     *
     * @param array<string, mixed> $checks
     * @return list<Problem>
     */
    public function problems(array $checks): array
    {
        $problems = [];
        foreach ($checks as $name => $check) {
            if ( ! is_array($check)) {
                continue;
            }
            $status = (string) ($check['status'] ?? 'unknown');
            if (self::gravity($status) < 1) {
                continue;
            }
            $problems[] = new Problem((string) $name, $status, $this->text((string) $name, $check), $this->fix((string) $name), (string) ($check['message'] ?? ''));
        }
        usort($problems, static fn (Problem $a, Problem $b): int => [self::gravity($b->status), $a->check] <=> [self::gravity($a->status), $b->check]);

        return $problems;
    }

    /**
     * Value of a check, formatted for a table.
     *
     * @param array<string, mixed> $check
     */
    public function value(string $name, array $check): string
    {
        $value = $check['value'] ?? null;
        if (null === $value) {
            return '';
        }
        if (is_bool($value)) {
            return $this->t->t($value ? 'value.yes' : 'value.no');
        }
        if (is_array($value)) {
            return (string) count($value);
        }
        if (str_ends_with($name, '_bytes') && is_numeric($value)) {
            return $this->t->bytes((int) $value);
        }
        if (in_array($name, ['backup.age', 'collector.age', 'tls.expiry_seconds'], true) && is_numeric($value)) {
            return $this->t->duration((int) $value);
        }

        return (string) (is_scalar($value) ? $value : '');
    }

    public static function gravity(string $status): int
    {
        return self::GRAVITY[$status] ?? 2;
    }

    /**
     * @param array<string, mixed> $check
     */
    private function text(string $name, array $check): string
    {
        $value = $check['value'] ?? null;
        $number = is_numeric($value) ? (int) $value : 0;
        switch ($name) {
            case 'backup.age':
                return null === $value ? $this->t->t('problem.backup.none') : $this->t->t('problem.backup.age', $this->t->duration($number));
            case 'database.reachable':
                return $this->t->t('problem.database');
            case 'dolibarr.upgrade_pending':
                return $this->t->t('problem.upgrade_pending');
            case 'php.compatibility':
                return $this->t->t('problem.php');
            case 'maintenance.enabled':
                return $this->t->t('problem.maintenance');
            case 'cron.failed':
            case 'cron.stale':
            case 'cron.stuck':
                return $this->t->t('problem.'.$name, $number);
            case 'storage.free_bytes':
                return $this->t->t('problem.storage', $this->t->bytes($number));
            case 'filecheck.modified':
            case 'filecheck.missing':
            case 'filecheck.added':
                return $this->t->t('problem.'.$name, $number);
            case 'security.install_lock':
                return $this->t->t('problem.install_lock');
            case 'security.conf_permissions':
                return $this->t->t('problem.conf_permissions');
            case 'security.prod_mode':
                return $this->t->t('problem.prod_mode');
            case 'security.login_failures_24h':
                return $this->t->t('problem.login_failures', $number);
            case 'collector.age':
                return $this->t->t('problem.collector', $this->t->duration($number));
            case 'tls.expiry_seconds':
                return $this->t->t('problem.tls', $this->t->duration($number));
            case 'dolicli.operation':
                return $this->t->t('problem.operation');
        }

        return $this->t->t('problem.generic', $this->label($name));
    }

    /**
     * Key of the instance card that fixes or explains the problem.
     */
    private function fix(string $name): string
    {
        return match (true) {
            'backup.age' === $name => 'b',
            'maintenance.enabled' === $name => 'm',
            'dolibarr.upgrade_pending' === $name => 'u',
            str_starts_with($name, 'cron.') => '4',
            default => '7',
        };
    }
}
