<?php

declare(strict_types=1);

namespace Dolifleet\Inventory;

/**
 * Targets common to every command: an instance, server:<alias>, tag:<tag>, all;
 * a comma for the union, --exclude=<target> to remove.
 */
final class Targets
{
    /**
     * @return array<string, Instance> Instances selected, in inventory order
     */
    public static function resolve(Inventory $inventory, string $target, string $exclude = ''): array
    {
        $selected = self::select($inventory, $target);
        if ('' !== $exclude) {
            foreach (array_keys(self::select($inventory, $exclude)) as $id) {
                unset($selected[$id]);
            }
        }
        if ([] === $selected) {
            throw new InventoryException("Target '{$target}' selects no instance.");
        }

        return $selected;
    }

    /**
     * @return array<string, Instance>
     */
    private static function select(Inventory $inventory, string $target): array
    {
        $selected = [];
        foreach (explode(',', $target) as $part) {
            $part = trim($part);
            if ('' === $part) {
                throw new InventoryException("Empty target in '{$target}'.");
            }
            foreach (self::one($inventory, $part) as $id => $instance) {
                $selected[$id] = $instance;
            }
        }

        // Keep the order of the inventory, not the order of the target.
        $ordered = [];
        foreach ($inventory->instances() as $id => $instance) {
            if (isset($selected[$id])) {
                $ordered[$id] = $instance;
            }
        }

        return $ordered;
    }

    /**
     * @return array<string, Instance>
     */
    private static function one(Inventory $inventory, string $part): array
    {
        if ('all' === $part) {
            return $inventory->instances();
        }
        if (str_starts_with($part, 'server:')) {
            $alias = mb_substr($part, 7);
            if ( ! isset($inventory->servers()[$alias])) {
                throw new InventoryException("Unknown server '{$alias}'.");
            }

            return array_filter($inventory->instances(), static fn (Instance $i): bool => $i->server === $alias);
        }
        if (str_starts_with($part, 'tag:')) {
            $tag = mb_substr($part, 4);

            return array_filter($inventory->instances(), static fn (Instance $i): bool => $i->hasTag($tag));
        }

        return [$part => $inventory->instance($part)];
    }
}
