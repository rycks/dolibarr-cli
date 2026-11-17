<?php

declare(strict_types=1);

namespace Dolifleet\Console\Terminal;

/**
 * Turns a frame (list of lines) into the escape sequences that update the screen: only the
 * rows that changed since the previous frame are rewritten, which keeps the console fluid on a
 * slow SSH link. A full redraw happens after a resize or on demand (Ctrl-L).
 */
final class Renderer
{
    /** @var list<string> */
    private array $previous = [];

    private int $cols = 0;

    private int $rows = 0;

    public function __construct(private readonly Style $style)
    {
    }

    public function style(): Style
    {
        return $this->style;
    }

    public function invalidate(): void
    {
        $this->previous = [];
    }

    /**
     * @param list<Line> $lines
     */
    public function render(array $lines, int $cols, int $rows): string
    {
        $out = '';
        if ($cols !== $this->cols || $rows !== $this->rows || [] === $this->previous) {
            $out .= "\e[0m\e[2J";
            $this->previous = [];
            $this->cols = $cols;
            $this->rows = $rows;
        }
        $encoded = [];
        for ($row = 0; $row < $rows; ++$row) {
            $line = $lines[$row] ?? Line::blank();
            // The last column of the last row is left empty: writing it scrolls some terminals.
            $width = $row === $rows - 1 ? $cols - 1 : $cols;
            $encoded[$row] = $this->encode($line->fit($width));
        }
        foreach ($encoded as $row => $text) {
            if (($this->previous[$row] ?? null) !== $text) {
                $out .= "\e[".($row + 1).';1H'.$text."\e[0m\e[K";
            }
        }
        $this->previous = $encoded;

        return $out;
    }

    /**
     * Plain text of a frame, without any escape sequence (tests, captures, --plain).
     *
     * @param list<Line> $lines
     * @return list<string>
     */
    public static function plain(array $lines, int $cols): array
    {
        $out = [];
        foreach ($lines as $line) {
            $out[] = rtrim($line->fit($cols)->text());
        }

        return $out;
    }

    private function encode(Line $line): string
    {
        $out = '';
        foreach ($line->segments() as [$text, $style]) {
            $sgr = $this->style->sgr($style);
            $out .= '' === $sgr ? $text : $sgr.$text."\e[0m";
        }

        return $out;
    }
}
