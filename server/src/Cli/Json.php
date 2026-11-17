<?php

namespace Dolicli\Cli;

use stdClass;

/**
 * JSON encoding that works the same from PHP 7.0 to the latest version.
 *
 * Old Dolibarr databases are often latin1: json_encode() returns false on a string
 * that is not valid UTF-8, and JSON_INVALID_UTF8_SUBSTITUTE only exists from PHP 7.2.
 * Every value is cleaned first, and an encoding failure is an explicit error.
 */
final class Json
{
    /**
     * Valid UTF-8 sequence (group 1) or a single byte that is not part of one (group 2).
     */
    const UTF8_SCAN = '/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|(.)/s';

    /**
     * @param mixed $value
     */
    public static function encode($value, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode(self::clean($value), $flags);
        if (!is_string($json) || JSON_ERROR_NONE !== json_last_error()) {
            throw new Failure('JSON_ENCODING_FAILED', 'Cannot encode the result as JSON: ' . json_last_error_msg(), ExitCode::FAILURE);
        }

        return $json;
    }

    /**
     * @return mixed Decoded value, null when the input is not valid JSON
     */
    public static function decode(string $json)
    {
        $value = json_decode($json, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return null;
        }

        return $value;
    }

    /**
     * Recursively turn every string into valid UTF-8 (invalid bytes read as Latin-1)
     * and every float that JSON cannot represent (INF, NAN) into null.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function clean($value)
    {
        if (is_string($value)) {
            return self::utf8($value);
        }
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return null;
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[is_string($key) ? self::utf8($key) : $key] = self::clean($item);
            }

            return $clean;
        }
        if ($value instanceof stdClass) {
            $clean = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $clean->{self::utf8((string) $key)} = self::clean($item);
            }

            return $clean;
        }

        return $value;
    }

    public static function utf8(string $text): string
    {
        if (1 === preg_match('//u', $text)) {
            return $text;
        }

        $fixed = preg_replace_callback(self::UTF8_SCAN, function (array $m) {
            if (isset($m[2])) {
                $byte = ord($m[2]);

                return chr(0xC0 | ($byte >> 6)) . chr(0x80 | ($byte & 0x3F));
            }

            return $m[1];
        }, $text);

        return is_string($fixed) ? $fixed : '';
    }

    /**
     * Empty PHP arrays encode as [], data objects must encode as {}.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|stdClass
     */
    public static function object(array $data)
    {
        return [] === $data ? new stdClass() : $data;
    }
}
