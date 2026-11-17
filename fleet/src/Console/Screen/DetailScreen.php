<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Terminal\Line;

/**
 * Every field of one element (a module, a user, a check, a command of the backstage), with a
 * scroll. Values received from a server are shown as they are, cleaned of control characters.
 */
final class DetailScreen extends Screen
{
    private int $offset = 0;

    /**
     * @param list<string> $breadcrumb
     * @param array<string, mixed> $fields
     * @param list<string>|null $command
     */
    public function __construct(private readonly array $breadcrumb, private readonly array $fields, private readonly ?array $command = null, private readonly bool $prod = false)
    {
    }

    public function breadcrumb(App $app): array
    {
        return $this->breadcrumb;
    }

    public function command(App $app): ?array
    {
        return $this->command;
    }

    public function prod(App $app): bool
    {
        return $this->prod;
    }

    public function keys(App $app): array
    {
        return [[$app->t->t('key.arrows'), $app->t->t('key.scroll')], [$app->t->t('key.esc'), $app->t->t('key.back')]];
    }

    public function handle(App $app, string $key): bool
    {
        $step = match ($key) {
            'up' => -1,
            'down' => 1,
            'pgup' => -10,
            'pgdn' => 10,
            default => 0,
        };
        if (0 === $step) {
            return false;
        }
        $this->offset = max(0, $this->offset + $step);

        return true;
    }

    public function body(App $app, int $width, int $height): array
    {
        $labelWidth = 4;
        foreach (array_keys($this->fields) as $label) {
            $labelWidth = max($labelWidth, min(28, Line::widthOf((string) $label)));
        }
        $lines = [];
        foreach ($this->fields as $label => $value) {
            $text = self::text($value);
            $first = true;
            foreach ('' === $text ? [''] : explode("\n", $text) as $part) {
                $lines[] = Line::of('  '.Line::pad($first ? (string) $label : '', $labelWidth).'  ', 'bold')->add($part);
                $first = false;
            }
        }
        $this->offset = min($this->offset, max(0, count($lines) - $height));

        return array_slice($lines, $this->offset, $height);
    }

    public static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (null === $value) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
