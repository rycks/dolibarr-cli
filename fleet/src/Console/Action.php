<?php

declare(strict_types=1);

namespace Dolifleet\Console;

/**
 * A write the operator is about to start: the command shown and run, the simulation shown
 * before, the instances touched, whether it can be undone.
 */
final class Action
{
    /**
     * @param list<string> $instances
     * @param list<string> $args Command shown and run (without --json nor --confirm)
     * @param list<string>|null $simulation Command run before the question (dry run, plan), null when none
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $target,
        public readonly array $instances,
        public readonly array $args,
        public readonly ?array $simulation,
        public readonly string $undo,
        public readonly bool $destructive = false,
        public readonly string $purpose = 'write',
    ) {
    }

    /**
     * Answer the pilot itself expects to the confirmation question (Batch, upgrade run): the
     * console asked its own question before, the pilot checks again.
     *
     * @param list<string> $protected
     */
    public function cliAnswer(array $protected): string
    {
        if ('upgrade' === $this->kind) {
            return $this->instances[0];
        }
        if (1 === count($this->instances)) {
            return in_array($this->instances[0], $protected, true) ? $this->instances[0] : 'yes';
        }

        return $this->target;
    }
}
