<?php

declare(strict_types=1);

namespace Dolifleet\Console\Terminal;

/**
 * Meaning -> SGR codes. The meaning never depends on the color alone (the screens always add a
 * sign or a word); without color (NO_COLOR, TERM=dumb) only bold and reverse remain, which
 * every terminal shows.
 */
final class Style
{
    /** @var array<string, string> name => SGR parameters with colors */
    private const COLORS = [
        'bold' => '1',
        'dim' => '2',
        'reverse' => '7',
        'title' => '1',
        'command' => '36',
        'key' => '1',
        'crit' => '1;31',
        'warn' => '33',
        'unknown' => '90',
        'running' => '36',
        'prod' => '1;7;31',
        'frame' => '90',
        'frame-prod' => '31',
        'error' => '1;31',
        'selected' => '7',
        'input' => '4',
        'ok' => '',
    ];

    /** @var array<string, string> name => SGR parameters without colors */
    private const MONO = [
        'bold' => '1',
        'reverse' => '7',
        'title' => '1',
        'key' => '1',
        'crit' => '1',
        'prod' => '1;7',
        'error' => '1',
        'selected' => '7',
        'input' => '4',
    ];

    public function __construct(private readonly bool $colors = true, private readonly bool $unicode = true)
    {
    }

    /**
     * Colors unless NO_COLOR is set (https://no-color.org) or the terminal is dumb.
     */
    public static function fromEnvironment(bool $forceNoColor = false): self
    {
        $noColor = getenv('NO_COLOR');
        $term = (string) getenv('TERM');
        $colors = ! $forceNoColor && ! (is_string($noColor) && '' !== $noColor) && 'dumb' !== $term;
        $locale = (string) (getenv('LC_ALL') ?: getenv('LC_CTYPE') ?: getenv('LANG'));

        return new self($colors, 1 === preg_match('/utf-?8/i', $locale));
    }

    public function colors(): bool
    {
        return $this->colors;
    }

    public function unicode(): bool
    {
        return $this->unicode;
    }

    /**
     * Escape sequence of a style (several names separated by spaces), '' for the plain text.
     */
    public function sgr(string $style): string
    {
        $codes = [];
        $table = $this->colors ? self::COLORS : self::MONO;
        foreach (preg_split('/\s+/', trim($style)) ?: [] as $name) {
            if ('' !== $name && isset($table[$name]) && '' !== $table[$name]) {
                $codes[] = $table[$name];
            }
        }

        return [] === $codes ? '' : "\e[".implode(';', $codes).'m';
    }

    /**
     * Horizontal rule: thin for the normal frame, double for the production.
     */
    public function rule(int $width, bool $prod = false): string
    {
        $char = $prod ? ($this->unicode ? "\u{2550}" : '=') : ($this->unicode ? "\u{2500}" : '-');

        return str_repeat($char, max(0, $width));
    }
}
