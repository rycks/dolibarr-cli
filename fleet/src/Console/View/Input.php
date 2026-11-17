<?php

declare(strict_types=1);

namespace Dolifleet\Console\View;

/**
 * One line of text typed in the console (filter, confirmation, palette, assistant). The cursor
 * stays at the end: enough for short values, and nothing to learn.
 */
final class Input
{
    public function __construct(public string $value = '', private readonly int $max = 200)
    {
    }

    /**
     * Applies a key; false when the key is not for the input (enter, esc, arrows...).
     */
    public function handle(string $key): bool
    {
        if ('backspace' === $key) {
            $this->value = mb_substr($this->value, 0, max(0, mb_strlen($this->value) - 1));

            return true;
        }
        if ('ctrl-u' === $key) {
            $this->value = '';

            return true;
        }
        if ('ctrl-w' === $key) {
            $this->value = (string) preg_replace('/\S+\s*$/u', '', rtrim($this->value));

            return true;
        }
        if ('space' === $key) {
            $key = ' ';
        }
        if (1 === mb_strlen($key) && mb_strlen($this->value) < $this->max) {
            $this->value .= $key;

            return true;
        }

        return false;
    }
}
