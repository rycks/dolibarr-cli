<?php

declare(strict_types=1);

namespace Dolifleet\Remote;

use Dolifleet\Inventory\Instance;
use Dolifleet\Inventory\Inventory;
use Dolifleet\Store\StateStore;
use RuntimeException;

/**
 * Run dolicli on instances, in the ephemeral mode (body on the standard input of PHP,
 * `sudo -n -u <run_as> <php> --`) or the installed one (`sudo -n -u <run_as> /usr/local/bin/dolicli`).
 * sudo -n fails at once instead of waiting for a password; without sudo when the SSH account
 * already is run_as. Every result is an envelope, or a failure built here.
 */
final class Dolicli
{
    public const INSTALLED_LAUNCHER = '/usr/local/bin/dolicli';

    public function __construct(
        private readonly Inventory $inventory,
        private readonly Transport $transport,
        private readonly Body $body,
        private readonly ?StateStore $store = null,
    ) {
    }

    /**
     * The installed dolicli is used only when the server is declared in installed mode and
     * dolifleet check found the same version as the body of the pilot (same schema major):
     * otherwise the pilot sends its own body.
     */
    public function usesInstalled(Instance $instance): bool
    {
        if ('installed' !== $this->inventory->serverMode($instance->server) || null === $this->store) {
            return false;
        }
        $check = $this->store->check($instance->id);

        return null !== $check && ($check['data']['installed_version'] ?? null) === $this->body->version();
    }

    public function body(): Body
    {
        return $this->body;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    /**
     * Words of the remote command and its standard input.
     *
     * @param list<string> $arguments dolicli arguments (secrets included: they travel in the stream)
     * @return array{server: string, command: list<string>, stdin: string}
     */
    public function job(Instance $instance, array $arguments): array
    {
        $arguments = array_merge($arguments, ['--format=json', '--dolpath='.$instance->htdocs]);
        if ('' !== $instance->dolibarrUser && ! $this->hasOption($arguments, 'as')) {
            $arguments[] = '--as='.$instance->dolibarrUser;
        }
        $prefix = $this->sudo($instance);
        $php = '' !== $instance->php ? $instance->php : 'php';
        if ($this->usesInstalled($instance)) {
            // Restricted sudo rule on the launcher: arguments on the command line, except the
            // secret, read by dolicli on its standard input (--password-stdin).
            $stdin = '';
            $sensitive = $this->body->commandOf($arguments)[0]['sensitive_params'] ?? [];
            foreach ($arguments as $index => $argument) {
                foreach ($sensitive as $name) {
                    foreach ([$name.'=', '--'.$name.'='] as $prefixOfSecret) {
                        if (str_starts_with($argument, $prefixOfSecret)) {
                            if ('' !== $stdin) {
                                throw new RuntimeException('Only one secret can be sent to an installed dolicli.');
                            }
                            $stdin = mb_substr($argument, mb_strlen($prefixOfSecret))."\n";
                            unset($arguments[$index]);
                        }
                    }
                }
            }
            if ('' !== $stdin) {
                $arguments[] = '--password-stdin';
            }

            return ['server' => $instance->server, 'command' => array_merge($prefix, [self::INSTALLED_LAUNCHER], array_values($arguments)), 'stdin' => $stdin];
        }

        return ['server' => $instance->server, 'command' => array_merge($prefix, [$php, '--']), 'stdin' => $this->body->stream($arguments)];
    }

    /**
     * dolicli on a server, outside any instance: as the SSH account, or as root through sudo -n
     * (setup, discovery of conf.php files in mode 0400). Extra blobs (files) travel in the stream.
     *
     * @param list<string> $arguments
     * @param array<string, string> $files
     */
    public function runOnServer(string $server, string $php, array $arguments, bool $root, array $files = [], int $timeout = 600): Outcome
    {
        $command = array_merge($root && 'root' !== $this->transport->remoteUser($server) ? ['sudo', '-n'] : [], ['' !== $php ? $php : 'php', '--']);
        $stdin = $this->body->stream(array_merge($arguments, ['--format=json']), $files);

        return Outcome::from(Instance::forServer($server, $php), $this->transport->run($server, $command, $stdin, $timeout));
    }

    /**
     * @param list<string> $arguments
     */
    public function run(Instance $instance, array $arguments, int $timeout = 600): Outcome
    {
        $job = $this->job($instance, $arguments);

        return Outcome::from($instance, $this->transport->run($job['server'], $job['command'], $job['stdin'], $timeout));
    }

    /**
     * @param array<string, Instance> $instances
     * @param list<string> $arguments
     * @return array<string, Outcome>
     */
    public function runMany(array $instances, array $arguments, int $parallel, int $timeout = 600): array
    {
        $jobs = [];
        foreach ($instances as $id => $instance) {
            $jobs[$id] = $this->job($instance, $arguments);
        }
        $outcomes = [];
        foreach ($this->transport->runMany($jobs, $parallel, $timeout) as $id => $result) {
            $outcomes[$id] = Outcome::from($instances[$id], $result);
        }

        return $outcomes;
    }

    /**
     * @return list<string>
     */
    public function sudo(Instance $instance): array
    {
        if ('' === $instance->runAs) {
            throw new RuntimeException("run_as of {$instance->id} is unknown: set it in the inventory (dolifleet discover {$instance->server} shows the owner of documents/).");
        }
        if ($this->transport->remoteUser($instance->server) === $instance->runAs) {
            return [];
        }

        return ['sudo', '-n', '-u', $instance->runAs];
    }

    /**
     * @param list<string> $arguments
     */
    private function hasOption(array $arguments, string $name): bool
    {
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--'.$name.'=') || str_starts_with($argument, $name.'=')) {
                return true;
            }
        }

        return false;
    }
}
