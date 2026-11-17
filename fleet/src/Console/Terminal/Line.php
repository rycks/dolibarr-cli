<?php

declare(strict_types=1);

namespace Dolifleet\Console\Terminal;

/**
 * One line of the screen: segments of text, each with a style (space separated names of Style).
 * Widths are display widths (an accented letter counts 1, a CJK character 2).
 */
final class Line
{
    /**
     * @param list<array{0: string, 1: string}> $segments
     */
    public function __construct(private array $segments = [])
    {
    }

    public static function of(string $text, string $style = ''): self
    {
        return new self([[self::clean($text), $style]]);
    }

    public static function blank(): self
    {
        return new self([]);
    }

    public function add(string $text, string $style = ''): self
    {
        $segments = $this->segments;
        $segments[] = [self::clean($text), $style];

        return new self($segments);
    }

    public function append(self $other): self
    {
        return new self(array_merge($this->segments, $other->segments));
    }

    /**
     * Same text, the style added to every segment (selected line, frame of the production).
     */
    public function styled(string $style): self
    {
        $segments = [];
        foreach ($this->segments as [$text, $own]) {
            $segments[] = [$text, trim($own.' '.$style)];
        }

        return new self($segments);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function text(): string
    {
        return implode('', array_column($this->segments, 0));
    }

    public function width(): int
    {
        return self::widthOf($this->text());
    }

    /**
     * Exactly $width columns: cut (with a final "...") or padded with spaces in the style of
     * $fill, so that a selected line is reversed to the right edge.
     */
    public function fit(int $width, string $fill = ''): self
    {
        if ($width <= 0) {
            return new self([]);
        }
        $current = $this->width();
        if ($current <= $width) {
            return $current === $width ? $this : $this->add(str_repeat(' ', $width - $current), $fill);
        }
        $ellipsis = $width >= 6 ? '...' : '';
        $room = $width - mb_strwidth($ellipsis);
        $segments = [];
        foreach ($this->segments as [$text, $style]) {
            if ($room <= 0) {
                break;
            }
            $cut = self::cut($text, $room);
            $segments[] = [$cut, $style];
            $room -= self::widthOf($cut);
        }
        if ('' !== $ellipsis) {
            $last = [] === $segments ? '' : $segments[count($segments) - 1][1];
            $segments[] = [$ellipsis, $last];
        }
        $line = new self($segments);

        return $line->width() < $width ? $line->add(str_repeat(' ', $width - $line->width()), $fill) : $line;
    }

    public static function widthOf(string $text): int
    {
        return mb_strwidth($text, 'UTF-8');
    }

    /**
     * Longest start of $text not wider than $width.
     */
    public static function cut(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, max(0, $width), '', 'UTF-8');
    }

    /**
     * $text padded or cut to $width columns.
     */
    public static function pad(string $text, int $width, bool $right = false): string
    {
        $text = self::widthOf($text) > $width ? self::cut($text, $width) : $text;
        $spaces = str_repeat(' ', max(0, $width - self::widthOf($text)));

        return $right ? $spaces.$text : $text.$spaces;
    }

    /**
     * Text from a server or a user: no control character, no escape sequence, valid UTF-8.
     */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace("\t", '    ', $text);

        return (string) preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $text);
    }
}
