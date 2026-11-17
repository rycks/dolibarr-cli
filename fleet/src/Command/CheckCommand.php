<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Inventory\Instance;
use Dolifleet\Remote\Dolicli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use RuntimeException;

/**
 * Test SSH, sudo, PHP, the reading of Dolibarr, the installed mode, the root access needed by
 * dolifleet setup and the differences between the inventory and instances.ini. The result is
 * cached: the version of PHP is checked here, before any body is sent.
 */
#[AsCommand(name: 'check', description: 'Test SSH, sudo, PHP, Dolibarr, installed mode, root access, instances.ini')]
final class CheckCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Instance, server:<alias>, tag:<tag>, all')->addTargetOptions()->addJsonOption();
    }

    protected function handle(): int
    {
        $results = [];
        $exit = self::EXIT_OK;
        $reachedAny = false;
        foreach ($this->targets((string) $this->input->getArgument('target')) as $id => $instance) {
            $checks = $this->checkInstance($instance);
            $this->store()->saveCheck($id, $checks);
            $results[$id] = $checks;
            $reachedAny = $reachedAny || $checks['ssh']['ok'];
            foreach ($checks as $check) {
                if (is_array($check) && false === $check['ok'] && 'warning' !== ($check['level'] ?? '')) {
                    $exit = self::EXIT_FAILED;
                }
            }
        }
        if ( ! $reachedAny) {
            $exit = self::EXIT_UNREACHABLE;
        }
        if ($this->isJson()) {
            $this->json(['exit_code' => $exit, 'instances' => $results]);

            return $exit;
        }
        foreach ($results as $id => $checks) {
            $this->output->writeln("<info>{$id}</info>");
            foreach ($checks as $name => $check) {
                if ( ! is_array($check)) {
                    continue;
                }
                $mark = $check['ok'] ? '<info>ok</info>  ' : ('warning' === ($check['level'] ?? '') ? '<comment>warn</comment>' : '<error>fail</error>');
                $this->output->writeln(sprintf('  %s %-14s %s', $mark, $name, self::clean((string) $check['detail'])));
            }
        }

        return $exit;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkInstance(Instance $instance): array
    {
        $transport = $this->transport();
        $php = '' !== $instance->php ? $instance->php : 'php';
        $checks = [];

        $ssh = $transport->run($instance->server, ['true'], '', 30);
        $checks['ssh'] = ['ok' => 0 === $ssh->exitCode, 'detail' => 0 === $ssh->exitCode ? 'connected as '.$transport->remoteUser($instance->server) : trim($ssh->stderr)];
        if (0 !== $ssh->exitCode) {
            return $checks;
        }

        // PHP 5 compatible probe: the body is only sent to PHP 7.0 or later with json.
        $probe = $transport->run($instance->server, [$php, '-r', 'echo PHP_VERSION, ",", (int) function_exists("json_encode");'], '', 30);
        $parts = explode(',', trim($probe->stdout));
        $phpOk = 0 === $probe->exitCode && 2 === count($parts) && version_compare($parts[0], '7.0.0', '>=') && '1' === $parts[1];
        $checks['php'] = [
            'ok' => $phpOk,
            'detail' => 0 !== $probe->exitCode ? "{$php} does not run: ".trim($probe->stderr) : "{$php} ".$parts[0].('1' === ($parts[1] ?? '') ? '' : ', json extension missing').(version_compare($parts[0], '7.0.0', '<') ? ', dolicli needs 7.0 or later' : ''),
            'version' => $parts[0],
        ];

        try {
            $sudo = $this->dolicli()->sudo($instance);
            $test = $transport->run($instance->server, array_merge($sudo, ['true']), '', 30);
            $tty = str_contains($test->stderr, 'tty');
            $checks['sudo'] = [
                'ok' => 0 === $test->exitCode,
                'detail' => [] === $sudo ? 'not needed (SSH account is '.$instance->runAs.')' : (0 === $test->exitCode ? 'sudo -n -u '.$instance->runAs.' allowed' : trim($test->stderr).($tty ? ' (requiretty: use the copy fallback or remove requiretty for this account)' : '')),
                'requiretty' => $tty,
            ];
        } catch (RuntimeException $e) {
            $checks['sudo'] = ['ok' => false, 'detail' => $e->getMessage()];
        }

        if ($phpOk && $checks['sudo']['ok']) {
            $doctor = $this->dolicli()->run($instance, ['env', 'doctor']);
            $problems = [];
            foreach ((array) ($doctor->data()['checks'] ?? []) as $check) {
                if ('error' === ($check['status'] ?? '')) {
                    $problems[] = (string) $check['message'];
                }
            }
            $checks['dolibarr'] = [
                'ok' => $doctor->ok(),
                'detail' => $doctor->ok() ? 'conf.php and documents/ readable by '.$instance->runAs : ($doctor->error.' '.implode('; ', $problems)),
            ];
        }

        $installed = $transport->run($instance->server, [$php, Dolicli::INSTALLED_LAUNCHER, 'version', '--format=json'], '', 30);
        $version = json_decode($installed->stdout, true)['data']['dolicli_version'] ?? '';
        $checks['installed'] = [
            'ok' => true,
            'level' => 'warning',
            'detail' => '' === $version ? 'not installed (ephemeral mode)' : 'dolicli '.$version.($version === $this->body()->version() ? ' (same as the pilot)' : ' (the pilot has '.$this->body()->version().': ephemeral mode used)'),
        ];
        $checks['installed_version'] = $version;

        // Same command as dolifleet setup and discover --sudo: PHP as root (a sudo rule may allow only PHP).
        $probeRoot = [$php, '-r', 'exit(0);'];
        $root = $transport->run($instance->server, 'root' === $transport->remoteUser($instance->server) ? $probeRoot : array_merge(['sudo', '-n'], $probeRoot), '', 30);
        $checks['root'] = ['ok' => 0 === $root->exitCode, 'level' => 'warning', 'detail' => 0 === $root->exitCode ? 'root access available for dolifleet setup' : 'no root access: dolifleet setup will fail ('.trim($root->stderr).')'];

        $ini = $transport->run($instance->server, ['cat', '/etc/dolicli/instances.ini'], '', 30);
        if (0 === $ini->exitCode) {
            $parsed = @parse_ini_string($ini->stdout, true, INI_SCANNER_RAW);
            $declared = is_array($parsed) && isset($parsed[$instance->id]) && is_array($parsed[$instance->id]) ? $parsed[$instance->id] : null;
            $gaps = [];
            if (null === $declared) {
                $gaps[] = 'not declared';
            } else {
                foreach (['htdocs' => $instance->htdocs, 'run_as' => $instance->runAs, 'php' => $instance->php] as $key => $expected) {
                    $actual = trim((string) ($declared[$key] ?? ''), '"');
                    if ('' !== $expected && $actual !== $expected) {
                        $gaps[] = "{$key} is {$actual}, inventory says {$expected}";
                    }
                }
            }
            $checks['instances.ini'] = ['ok' => [] === $gaps, 'level' => 'warning', 'detail' => [] === $gaps ? 'consistent with the inventory' : implode('; ', $gaps).' (dolifleet setup rewrites it)'];
        }

        return $checks;
    }
}
