<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;

/**
 * One screen of the console. The App draws the five zones around its body: breadcrumb and
 * counters, equivalent command, body, band of operations, bar of keys.
 */
abstract class Screen
{
    /**
     * @return list<string>
     */
    abstract public function breadcrumb(App $app): array;

    /**
     * @return list<Line>
     */
    abstract public function body(App $app, int $width, int $height): array;

    /**
     * Arguments of the dolifleet command that produces this screen, null when there is none.
     *
     * @return list<string>|null
     */
    public function command(App $app): ?array
    {
        return null;
    }

    public function counters(App $app): string
    {
        return '';
    }

    /**
     * Whether the screen touches a production instance: red frame, PROD badge.
     */
    public function prod(App $app): bool
    {
        return false;
    }

    /**
     * Keys of this screen for the bar, most used first: [key, description].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function keys(App $app): array
    {
        return [];
    }

    /**
     * True when the key was used. Keys not used go to the global keys of the console.
     */
    public function handle(App $app, string $key): bool
    {
        return false;
    }

    /**
     * True while the operator types in a field: every key goes to the screen.
     */
    public function captures(): bool
    {
        return false;
    }

    public function taskFinished(App $app, Task $task): void
    {
    }

    /**
     * Lines of the help specific to this screen (meaning of the columns...).
     *
     * @return list<string>
     */
    public function help(App $app): array
    {
        return [];
    }
}
