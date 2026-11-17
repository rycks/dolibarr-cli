<?php

namespace Dolicli\System;

/**
 * Filesystem of a path, read in /proc/mounts (flock is not reliable on NFS).
 */
final class Mounts
{
    const NETWORK_TYPES = ['nfs', 'nfs4', 'cifs', 'smb3', 'smbfs', 'fuse.sshfs', 'glusterfs', 'ceph'];

    /**
     * @return array{mount_point: string, type: string}|null
     */
    public static function of(string $path, string $mountsFile = '/proc/mounts')
    {
        $content = @file_get_contents($mountsFile);
        $real = realpath($path);
        if (!is_string($content) || false === $real) {
            return null;
        }
        $best = null;
        foreach (explode("\n", $content) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (!is_array($fields) || count($fields) < 3) {
                continue;
            }
            $mountPoint = str_replace('\040', ' ', $fields[1]);
            $prefix = rtrim($mountPoint, '/') . '/';
            if ($real === $mountPoint || 0 === strpos($real . '/', $prefix) || '/' === $mountPoint) {
                if (null === $best || strlen($mountPoint) >= strlen($best['mount_point'])) {
                    $best = ['mount_point' => $mountPoint, 'type' => $fields[2]];
                }
            }
        }

        return $best;
    }

    public static function isNetwork(string $type): bool
    {
        return in_array($type, self::NETWORK_TYPES, true);
    }
}
