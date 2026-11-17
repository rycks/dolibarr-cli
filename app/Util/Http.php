<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Minimal HTTPS client (stream wrapper) with timeout and status check.
 */
final class Http
{
    public const USER_AGENT = 'dolicli';

    private static string $lastError = '';

    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * GET a URL, null on network error or non-2xx status (reason in lastError() and the log).
     */
    public static function get(string $url, int $timeout = 20): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'user_agent' => self::USER_AGENT,
                'follow_location' => 1,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if (false === $body) {
            $error = error_get_last();
            self::failWith("GET {$url} failed: ".($error['message'] ?? 'network error'));

            return null;
        }
        $headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : $http_response_header;

        $status = 0;
        foreach ($headers as $header) {
            if (1 === preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            self::failWith("GET {$url} returned HTTP {$status}");

            return null;
        }

        return $body;
    }

    /**
     * Download a URL into a file, false on error.
     */
    public static function download(string $url, string $target, int $timeout = 60): bool
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $timeout, 'user_agent' => self::USER_AGENT, 'follow_location' => 1],
        ]);

        $in = @fopen($url, 'rb', false, $context);
        if (false === $in) {
            $error = error_get_last();
            self::failWith("GET {$url} failed: ".($error['message'] ?? 'network error'));

            return false;
        }
        $out = @fopen($target, 'wb');
        if (false === $out) {
            fclose($in);
            self::failWith("Cannot write {$target}");

            return false;
        }

        $copied = stream_copy_to_stream($in, $out);
        $meta = stream_get_meta_data($in);
        fclose($in);
        fclose($out);

        if (false === $copied || 0 === $copied || ! empty($meta['timed_out'])) {
            self::failWith("Download of {$url} failed or timed out");
            @unlink($target);

            return false;
        }

        return true;
    }

    private static function failWith(string $message): void
    {
        self::$lastError = $message;
        DolibarrHelper::log('http: '.$message, LOG_ERR);
    }
}
