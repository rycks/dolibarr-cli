<?php

declare(strict_types=1);

namespace Dolifleet\Console;

use Dolifleet\Console\Terminal\Keys;
use Dolifleet\Console\Terminal\Renderer;
use Dolifleet\Console\Terminal\Terminal;

/**
 * The loop: about every 100 ms, finished commands are collected, the screen is redrawn where
 * it changed, and the keys typed are applied. Nothing in the loop waits for a server.
 */
final class Console
{
    public const TICK_MS = 100;

    /** Silence after a lone ESC before it counts as the Escape key. */
    public const ESC_MS = 40;

    public function __construct(private readonly App $app, private readonly Terminal $terminal, private readonly Renderer $renderer)
    {
    }

    public function run(): void
    {
        $this->terminal->open();
        try {
            $pending = '';
            while ( ! $this->app->quitRequested()) {
                $this->app->tick();
                [$cols, $rows] = $this->terminal->size();
                if ($this->app->takeRedraw()) {
                    $this->renderer->invalidate();
                }
                $output = $this->renderer->render($this->app->frame($cols, $rows), $cols, $rows).$this->app->takeOutput();
                if ('' !== $output) {
                    $this->terminal->write($output);
                }
                $pending .= $this->terminal->read(self::TICK_MS);
                if ('' === $pending) {
                    continue;
                }
                [$keys, $pending] = Keys::decode($pending);
                if ('' !== $pending) {
                    $pending .= $this->terminal->read(self::ESC_MS);
                    [$more, $pending] = Keys::decode($pending, true);
                    $keys = array_merge($keys, $more);
                }
                foreach ($keys as $key) {
                    $this->app->key($key);
                    if ($this->app->quitRequested()) {
                        break;
                    }
                }
            }
        } finally {
            $this->terminal->close();
        }
    }
}
