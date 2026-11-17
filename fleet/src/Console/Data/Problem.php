<?php

declare(strict_types=1);

namespace Dolifleet\Console\Data;

/**
 * Something that needs attention on an instance: the check, its status, the text in the
 * language of the console, the key that fixes it, and the original message of dolicli.
 */
final class Problem
{
    public function __construct(
        public readonly string $check,
        public readonly string $status,
        public readonly string $text,
        public readonly string $fix,
        public readonly string $message,
    ) {
    }
}
