<?php

declare(strict_types=1);

namespace Dolifleet\Remote;

use Dolifleet\Paths;
use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * SSH host key of a server. The transport runs with BatchMode=yes, so an unknown host key makes
 * the connection fail: probe it, read the keys the server presents (ssh-keyscan on the host and
 * port of the alias), show their fingerprints, append them to ~/.ssh/known_hosts once accepted.
 */
final class HostKey
{
    public const KNOWN = 'known';
    public const UNKNOWN = 'unknown';
    public const UNREACHABLE = 'unreachable';

    private readonly string $ssh;

    public function __construct(private readonly Transport $transport)
    {
        // Same ssh binary as the transport (DOLIFLEET_SSH), so ssh -G reads the same configuration.
        $this->ssh = ($transport instanceof SshTransport ? $transport : new SshTransport())->sshBinary();
    }

    /**
     * @return array{state: string, error: string} state: KNOWN, UNKNOWN or UNREACHABLE (error set)
     */
    public function probe(string $server): array
    {
        $probe = $this->transport->run($server, ['true'], '', 30);
        if (0 === $probe->exitCode) {
            return ['state' => self::KNOWN, 'error' => ''];
        }
        // A changed key also ends with "Host key verification failed": appending the new key
        // would hide the warning, the old line has to be removed knowingly.
        if (str_contains($probe->stderr, 'REMOTE HOST IDENTIFICATION HAS CHANGED')) {
            return ['state' => self::UNREACHABLE, 'error' => 'The host key of '.$server.' has changed since it was added to known_hosts: check the new key with the administrator of the server, then remove the old one with ssh-keygen -R <host>.'];
        }
        if ( ! str_contains($probe->stderr, 'Host key verification failed')) {
            return ['state' => self::UNREACHABLE, 'error' => 'Cannot connect to '.$server.': '.trim($probe->stderr)];
        }

        return ['state' => self::UNKNOWN, 'error' => ''];
    }

    /**
     * Keys presented by the server, as known_hosts lines.
     */
    public function scan(string $server): string
    {
        $config = new Process([$this->ssh, '-G', $server], null, null, null, 10);
        $config->run();
        preg_match('/^hostname (\S+)$/m', $config->getOutput(), $host);
        preg_match('/^port (\d+)$/m', $config->getOutput(), $port);
        $scan = new Process(['ssh-keyscan', '-p', $port[1] ?? '22', $host[1] ?? $server], null, null, null, 30);
        $scan->run();
        $keys = trim($scan->getOutput());
        if ('' === $keys) {
            throw new RuntimeException('Cannot read the host key of '.$server.': '.trim($scan->getErrorOutput()));
        }

        return $keys;
    }

    /**
     * @return list<string> One line per key, as ssh-keygen -l prints it ("256 SHA256:... host (ED25519)")
     */
    public function fingerprints(string $keys): array
    {
        $process = new Process(['ssh-keygen', '-lf', '-'], null, null, $keys."\n", 30);
        $process->run();
        $lines = array_values(array_filter(array_map('trim', explode("\n", $process->getOutput())), fn (string $line): bool => '' !== $line));
        if ([] === $lines) {
            throw new RuntimeException('Cannot compute the fingerprint of the host key: '.trim($process->getErrorOutput()));
        }

        return $lines;
    }

    /**
     * Appends the scanned keys to ~/.ssh/known_hosts.
     *
     * @return string The known_hosts file
     */
    public function accept(string $keys): string
    {
        $knownHosts = Paths::home().'/.ssh/known_hosts';
        Paths::ensure(dirname($knownHosts));
        if (false === file_put_contents($knownHosts, $keys."\n", FILE_APPEND | LOCK_EX)) {
            throw new RuntimeException("Cannot write {$knownHosts}");
        }

        return $knownHosts;
    }
}
