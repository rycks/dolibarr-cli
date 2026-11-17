<?php

namespace Dolicli\Cli;

/**
 * Default text rendering of command data: "Label: value" lines, tables for lists of records.
 */
final class TextRenderer
{
    /**
     * @param array<string, mixed> $data
     */
    public static function generic(array $data, int $indent = 0): string
    {
        $out = '';
        $pad = str_repeat('  ', $indent);
        $labelWidth = 0;
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                $labelWidth = max($labelWidth, Text::width(self::label((string) $key)));
            }
        }

        foreach ($data as $key => $value) {
            $label = self::label((string) $key);
            if (!is_array($value)) {
                $out .= $pad . Text::pad($label . ':', $labelWidth + 1) . ' ' . Text::line(Text::scalar($value)) . "\n";
                continue;
            }
            if ([] === $value) {
                $out .= $pad . $label . ": (none)\n";
                continue;
            }
            if (self::isList($value) && self::isListOfRecords($value)) {
                $out .= $pad . $label . ":\n" . self::indent(self::records($value), $indent + 1);
                continue;
            }
            if (self::isList($value)) {
                $out .= $pad . $label . ":\n";
                foreach ($value as $item) {
                    $out .= $pad . '  - ' . Text::line(Text::scalar($item)) . "\n";
                }
                continue;
            }
            $out .= $pad . $label . ":\n" . self::generic($value, $indent + 1);
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    public static function records(array $records): string
    {
        if ([] === $records) {
            return "(none)\n";
        }
        $headers = [];
        foreach ($records as $record) {
            foreach (array_keys($record) as $key) {
                $headers[(string) $key] = true;
            }
        }
        $headers = array_keys($headers);
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($headers as $header) {
                $row[] = isset($record[$header]) ? $record[$header] : '';
            }
            $rows[] = $row;
        }

        return Text::table($headers, $rows);
    }

    public static function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param array<mixed> $value
     */
    private static function isListOfRecords(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_array($item) || self::isList($item)) {
                return false;
            }
            foreach ($item as $field) {
                if (is_array($field) && [] !== $field && !self::isList($field)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function indent(string $text, int $level): string
    {
        $pad = str_repeat('  ', $level);
        $lines = explode("\n", rtrim($text, "\n"));

        return $pad . implode("\n" . $pad, $lines) . "\n";
    }
}
