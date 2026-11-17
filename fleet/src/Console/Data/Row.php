<?php

declare(strict_types=1);

namespace Dolifleet\Console\Data;

use Dolifleet\Inventory\Instance;
use Dolifleet\Inventory\Inventory;
use Dolifleet\Store\StateStore;

/**
 * One instance as the console shows it: inventory, last state known locally (never fetched to
 * draw the screen), and the result of the last refresh of this session.
 */
final class Row
{
    /**
     * @param list<Problem> $problems
     * @param array<string, mixed> $checks
     */
    public function __construct(
        public readonly Instance $instance,
        public readonly bool $prod,
        public readonly string $status,
        public readonly ?int $age,
        public readonly array $checks,
        public readonly array $problems,
        public readonly ?string $url,
        public readonly string $unreachable = '',
        public readonly bool $refreshing = false,
    ) {
    }

    /**
     * @param array<string, string> $unreachable instance => error of the last refresh
     * @param array<string, bool> $refreshing
     * @return list<self> In inventory order
     */
    public static function all(Inventory $inventory, StateStore $store, Checks $checks, array $unreachable = [], array $refreshing = []): array
    {
        $rows = [];
        foreach ($inventory->instances() as $id => $instance) {
            $rows[] = self::one($inventory, $store, $checks, $instance, $unreachable[$id] ?? '', $refreshing[$id] ?? false);
        }

        return $rows;
    }

    public static function one(Inventory $inventory, StateStore $store, Checks $checks, Instance $instance, string $unreachable = '', bool $refreshing = false): self
    {
        $last = $store->last($instance->id);
        $document = $last['document'] ?? [];
        $states = is_array($document['checks'] ?? null) ? $document['checks'] : [];
        $generated = (int) ($document['generated_at_unix'] ?? 0);
        $url = $document['instance']['url'] ?? null;

        return new self(
            $instance,
            $instance->isProtected($inventory->protectedTags()),
            null === $last ? 'never' : (string) ($document['status'] ?? 'unknown'),
            null === $last ? null : max(0, time() - ($generated > 0 ? $generated : $last['received_at'])),
            $states,
            $checks->problems($states),
            is_string($url) && '' !== $url ? $url : null,
            $unreachable,
            $refreshing,
        );
    }

    public function id(): string
    {
        return $this->instance->id;
    }

    /**
     * Gravity used to sort: unreachable and critical first, never seen just after.
     */
    public function gravity(): int
    {
        if ('' !== $this->unreachable) {
            return 3;
        }

        return 'never' === $this->status ? 2 : Checks::gravity($this->status);
    }

    public function value(string $check): mixed
    {
        return is_array($this->checks[$check] ?? null) ? ($this->checks[$check]['value'] ?? null) : null;
    }

    public function version(): string
    {
        $version = $this->value('dolibarr.version');

        return is_scalar($version) ? (string) $version : '';
    }

    public function php(): string
    {
        $php = $this->value('php.cli_version');
        if ( ! is_scalar($php) || '' === (string) $php) {
            return '';
        }
        $parts = explode('.', (string) $php);

        return $parts[0].(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    public function maintenance(): ?bool
    {
        $value = $this->value('maintenance.enabled');

        return is_bool($value) ? $value : null;
    }

    public function backupAge(): ?int
    {
        $value = $this->value('backup.age');

        return is_numeric($value) ? (int) $value : null;
    }
}
