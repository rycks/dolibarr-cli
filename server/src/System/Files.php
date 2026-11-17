<?php

namespace Dolicli\System;

use Dolicli\Cli\Failure;

/**
 * File helpers: atomic writes, private directories, size rotation.
 */
final class Files
{
    /**
     * Write a file atomically: temporary file in the same directory, explicit chmod
     * (the umask of cron may give 0600), then rename().
     */
    public static function writeAtomic(string $path, string $content, int $mode = 0640)
    {
        // tempnam() returns a resolved path: compare with the resolved directory
        // (DOL_DATA_ROOT is often written htdocs/../documents).
        $real = realpath(dirname($path));
        $dir = false === $real ? dirname($path) : $real;
        $path = $dir . '/' . basename($path);
        $tmp = @tempnam($dir, '.' . basename($path) . '.');
        if (false === $tmp || dirname($tmp) !== $dir) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
            Log::error("cannot create a temporary file in {$dir}");
            throw Failure::operation('WRITE_FAILED', "Cannot create a temporary file in {$dir}.");
        }
        if (false === @file_put_contents($tmp, $content) || !@chmod($tmp, $mode)) {
            @unlink($tmp);
            Log::error("cannot write {$tmp}");
            throw Failure::operation('WRITE_FAILED', "Cannot write {$path}.");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            Log::error("cannot rename {$tmp} to {$path}");
            throw Failure::operation('WRITE_FAILED', "Cannot write {$path}.");
        }
    }

    /**
     * Create a directory (and its parents) with a mode, fail with a clear error.
     */
    public static function ensureDir(string $dir, int $mode = 0750)
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            Log::error("cannot create directory {$dir}");
            throw Failure::operation('MKDIR_FAILED', "Cannot create directory {$dir}.");
        }
        @chmod($dir, $mode);
    }

    /**
     * Rename $path to $path.1, .2... when it is larger than $maxBytes.
     */
    public static function rotate(string $path, int $maxBytes, int $keep = 3)
    {
        clearstatcache(true, $path);
        if (!is_file($path) || filesize($path) < $maxBytes) {
            return;
        }
        for ($i = $keep - 1; $i >= 1; $i--) {
            if (is_file($path . '.' . $i)) {
                @rename($path . '.' . $i, $path . '.' . ($i + 1));
            }
        }
        if (!@rename($path, $path . '.1')) {
            Log::error("cannot rotate {$path}");
        }
    }

    /**
     * Private temporary directory (mode 0700), under $parent or the system temporary directory.
     *
     * @param string|null $parent
     */
    public static function tempDir($parent = null): string
    {
        $base = rtrim(null === $parent ? sys_get_temp_dir() : $parent, '/');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $dir = $base . '/dolicli-' . bin2hex(random_bytes(8));
            if (@mkdir($dir, 0700)) {
                return $dir;
            }
        }
        Log::error("cannot create a temporary directory in {$base}");
        throw Failure::operation('TEMPDIR_FAILED', "Cannot create a temporary directory in {$base}.");
    }

    /**
     * Remove a directory tree without following symbolic links.
     */
    public static function removeTree(string $path)
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::removeTree($path . '/' . $entry);
                }
            }
        }
        if (!@rmdir($path)) {
            Log::error("cannot remove directory {$path}");
        }
    }
}
