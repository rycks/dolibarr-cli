<?php

namespace Dolicli\System;

/**
 * Accounts and identities, with or without the posix extension.
 *
 * Without posix, the effective user is read in /proc/self/status (never getmyuid(),
 * which returns the owner of the script) and names in /etc/passwd and /etc/group.
 */
final class Posix
{
    /** @var string */
    private static $procStatus = '/proc/self/status';

    /** @var string */
    private static $passwd = '/etc/passwd';

    /** @var string */
    private static $group = '/etc/group';

    /**
     * Test hook: read identities from other files.
     */
    public static function useFiles(string $procStatus, string $passwd, string $group)
    {
        self::$procStatus = $procStatus;
        self::$passwd = $passwd;
        self::$group = $group;
    }

    public static function hasExtension(): bool
    {
        return function_exists('posix_geteuid');
    }

    /**
     * Effective uid, -1 when it cannot be determined.
     */
    public static function effectiveUid(): int
    {
        if (self::hasExtension()) {
            return posix_geteuid();
        }

        return self::procId('Uid');
    }

    public static function effectiveGid(): int
    {
        if (function_exists('posix_getegid')) {
            return posix_getegid();
        }

        return self::procId('Gid');
    }

    public static function isRoot(): bool
    {
        return 0 === self::effectiveUid();
    }

    public static function currentUserName(): string
    {
        $uid = self::effectiveUid();

        return $uid >= 0 ? self::userName($uid) : '';
    }

    /**
     * Account name of a uid, the number as a string when unknown.
     */
    public static function userName(int $uid): string
    {
        if (function_exists('posix_getpwuid')) {
            $info = posix_getpwuid($uid);
            if (is_array($info)) {
                return (string) $info['name'];
            }
        }
        foreach (self::entries(self::$passwd) as $fields) {
            if (isset($fields[2]) && (string) $uid === $fields[2]) {
                return $fields[0];
            }
        }

        return (string) $uid;
    }

    public static function groupName(int $gid): string
    {
        if (function_exists('posix_getgrgid')) {
            $info = posix_getgrgid($gid);
            if (is_array($info)) {
                return (string) $info['name'];
            }
        }
        foreach (self::entries(self::$group) as $fields) {
            if (isset($fields[2]) && (string) $gid === $fields[2]) {
                return $fields[0];
            }
        }

        return (string) $gid;
    }

    /**
     * uid of an account name, -1 when unknown.
     */
    public static function userId(string $name): int
    {
        if (function_exists('posix_getpwnam')) {
            $info = posix_getpwnam($name);
            if (is_array($info)) {
                return (int) $info['uid'];
            }
        }
        foreach (self::entries(self::$passwd) as $fields) {
            if ($fields[0] === $name && isset($fields[2])) {
                return (int) $fields[2];
            }
        }

        return -1;
    }

    /**
     * gid of a group name, -1 when unknown.
     */
    public static function groupId(string $name): int
    {
        if (function_exists('posix_getgrnam')) {
            $info = posix_getgrnam($name);
            if (is_array($info)) {
                return (int) $info['gid'];
            }
        }
        foreach (self::entries(self::$group) as $fields) {
            if ($fields[0] === $name && isset($fields[2])) {
                return (int) $fields[2];
            }
        }

        return -1;
    }

    /**
     * Owner name of a file, empty string when it cannot be read.
     */
    public static function ownerName(string $path): string
    {
        $uid = @fileowner($path);

        return false === $uid ? '' : self::userName($uid);
    }

    public static function groupOwnerName(string $path): string
    {
        $gid = @filegroup($path);

        return false === $gid ? '' : self::groupName($gid);
    }

    /**
     * Account that ran sudo (the SSH account of the administrator), empty without sudo.
     */
    public static function sudoUser(): string
    {
        $user = getenv('SUDO_USER');

        return is_string($user) ? $user : '';
    }

    /**
     * Account to record in journals: the sudo caller, or the current account.
     */
    public static function operator(): string
    {
        $sudo = self::sudoUser();

        return '' !== $sudo ? $sudo : self::currentUserName();
    }

    /**
     * Account name format accepted for run_as.
     */
    public static function isValidAccountName(string $name): bool
    {
        return 1 === preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/', $name);
    }

    private static function procId(string $field): int
    {
        $status = @file_get_contents(self::$procStatus);
        if (is_string($status) && 1 === preg_match('/^' . $field . ':\s+(\d+)\s+(\d+)/m', $status, $m)) {
            return (int) $m[2];
        }

        return -1;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private static function entries(string $file): array
    {
        $content = @file_get_contents($file);
        if (!is_string($content)) {
            return [];
        }
        $entries = [];
        foreach (explode("\n", $content) as $line) {
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $entries[] = explode(':', $line);
        }

        return $entries;
    }
}
