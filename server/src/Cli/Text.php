<?php

namespace Dolicli\Cli;

/**
 * Text helpers that do not need mbstring (absent on some PHP 7 servers).
 */
final class Text
{
    /**
     * Remove control characters (C0 except tab and newline, DEL, C1): no ANSI sequence
     * coming from the database reaches the terminal.
     */
    public static function safe(string $text): string
    {
        $text = Json::utf8($text);
        $clean = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $text);

        return is_string($clean) ? $clean : '';
    }

    /**
     * Same as safe(), on one line (tabs and newlines become spaces).
     */
    public static function line(string $text): string
    {
        return str_replace(["\t", "\n"], ' ', self::safe($text));
    }

    /**
     * Number of characters of a UTF-8 string.
     */
    public static function width(string $text): int
    {
        $count = preg_match_all('/./us', $text);

        return is_int($count) ? $count : strlen($text);
    }

    public static function pad(string $text, int $width): string
    {
        $missing = $width - self::width($text);

        return $missing > 0 ? $text . str_repeat(' ', $missing) : $text;
    }

    /**
     * Render rows as an aligned text table.
     *
     * @param array<int, string> $headers
     * @param array<int, array<int, mixed>> $rows
     */
    public static function table(array $headers, array $rows): string
    {
        $cells = [];
        foreach ($rows as $row) {
            $line = [];
            foreach (array_values($row) as $value) {
                $line[] = self::line(self::scalar($value));
            }
            $cells[] = $line;
        }

        $widths = [];
        foreach ($headers as $index => $header) {
            $widths[$index] = self::width($header);
        }
        foreach ($cells as $line) {
            foreach ($line as $index => $value) {
                $widths[$index] = max(isset($widths[$index]) ? $widths[$index] : 0, self::width($value));
            }
        }

        $out = '';
        $all = array_merge([$headers], $cells);
        foreach ($all as $number => $line) {
            $parts = [];
            foreach ($widths as $index => $width) {
                $parts[] = self::pad(isset($line[$index]) ? $line[$index] : '', $width);
            }
            $out .= rtrim(implode('  ', $parts)) . "\n";
            if (0 === $number) {
                $rule = [];
                foreach ($widths as $width) {
                    $rule[] = str_repeat('-', $width);
                }
                $out .= implode('  ', $rule) . "\n";
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    public static function scalar($value): string
    {
        if (null === $value) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::scalar($item);
            }

            return implode(', ', $parts);
        }
        if (is_object($value)) {
            return self::scalar(get_object_vars($value));
        }

        return (string) $value;
    }

    /**
     * Mask a secret: its length and first characters only.
     */
    public static function mask(string $secret): string
    {
        $length = strlen($secret);
        if (0 === $length) {
            return '';
        }

        return substr($secret, 0, min(4, intdiv($length, 4))) . '... (' . $length . ' characters, --show-secrets to display)';
    }
}
