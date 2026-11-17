<?php

declare(strict_types=1);

use Opis\JsonSchema\Validator;

/**
 * PHP binaries of the compatibility matrix present on this machine.
 *
 * @param  bool  $withSqlite  Only those able to load the SQLite test Dolibarr
 * @return array<string, string> version => binary
 */
function phpMatrix(bool $withSqlite = false): array
{
    $found = [];
    foreach (['7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'] as $version) {
        $binary = '/usr/bin/php'.$version;
        if ( ! is_executable($binary)) {
            continue;
        }
        if ($withSqlite) {
            exec(escapeshellarg($binary).' -m 2>/dev/null', $modules);
            $modules = array_map('strtolower', $modules);
            if ( ! in_array('sqlite3', $modules, true) || ! in_array('pdo_sqlite', $modules, true) || ! in_array('mbstring', $modules, true)) {
                $modules = [];

                continue;
            }
            $modules = [];
        }
        $found[$version] = $binary;
    }

    return $found;
}

/**
 * Private directories standing for /etc/dolicli, /var/lib/dolicli, /usr/local and the cron file.
 *
 * @return array<string, string>
 */
function serverEnvDirs(): array
{
    $base = DOLICLI_TEST_TMP.'/system';
    foreach (['etc', 'state', 'prefix', 'cron'] as $dir) {
        if ( ! is_dir($base.'/'.$dir)) {
            mkdir($base.'/'.$dir, 0755, true);
        }
    }

    return [
        'DOLICLI_ETC_DIR' => $base.'/etc',
        'DOLICLI_STATE_DIR' => $base.'/state',
        'DOLICLI_PREFIX' => $base.'/prefix',
        'DOLICLI_CRON_FILE' => $base.'/cron/dolicli',
    ];
}

function resetServerEnvDirs(): void
{
    $base = DOLICLI_TEST_TMP.'/system';
    if (is_dir($base)) {
        exec('rm -rf '.escapeshellarg($base));
    }
}

/**
 * @param  array<string, string|false>  $env
 * @return array<string, string>
 */
function serverEnvironment(array $env): array
{
    $base = getenv();
    unset($base['DOLPATH'], $base['SUDO_USER']);
    $base = array_merge($base, serverEnvDirs());
    $base['DOLPATH'] = DOLICLI_DOLPATH;
    foreach ($env as $name => $value) {
        if (false === $value) {
            unset($base[$name]);
        } else {
            $base[$name] = $value;
        }
    }

    return $base;
}

/**
 * Run the built dolicli through its launcher.
 *
 * @param  array<int, string>  $args
 * @param  array<string, string|false>  $env
 * @return array{code: int, out: string, err: string}
 */
function server(array $args, ?string $stdin = null, array $env = [], string $php = PHP_BINARY, ?string $cwd = null): array
{
    return runProcess(array_merge([$php, DOLICLI_TEST_DIST.'/dolicli'], $args), $stdin, serverEnvironment($env), $cwd ?? DOLICLI_ROOT);
}

/**
 * Run the body in the ephemeral mode: sent on stdin, arguments in $DOLICLI_ARGV.
 *
 * @param  array<int, string>  $args
 * @param  array<string, string|false>  $env
 * @return array{code: int, out: string, err: string}
 */
function serverEphemeral(array $args, array $env = [], string $php = PHP_BINARY): array
{
    $prefix = '<?php namespace { $DOLICLI_ARGV = '.var_export(array_merge(['dolicli'], $args), true).'; } ?>';
    $body = (string) file_get_contents(DOLICLI_TEST_DIST.'/dolicli.php');

    return runProcess([$php, '--'], $prefix.$body, serverEnvironment($env), DOLICLI_ROOT);
}

/**
 * Run a command and decode its JSON envelope, validated against the schema.
 *
 * @param  array<int, string>  $args
 * @param  array<string, string|false>  $env
 * @return array{code: int, json: array<string, mixed>, err: string}
 */
function serverJson(array $args, ?string $stdin = null, array $env = [], string $php = PHP_BINARY): array
{
    $result = server(array_merge($args, ['--format=json']), $stdin, $env, $php);
    $json = json_decode($result['out'], true);
    if ( ! is_array($json)) {
        throw new RuntimeException("Not a JSON envelope (exit {$result['code']}):\n{$result['out']}\n{$result['err']}");
    }
    assertMatchesSchema($result['out'], 'envelope');

    return ['code' => $result['code'], 'json' => $json, 'err' => $result['err']];
}

function assertMatchesSchema(string $json, string $schema): void
{
    $validator = new Validator();
    $validator->resolver()?->registerFile(
        'https://github.com/rycks/dolibarr-cli/schema/'.$schema.'.schema.json',
        DOLICLI_ROOT.'/schema/'.$schema.'.schema.json'
    );
    $result = $validator->validate(json_decode($json), 'https://github.com/rycks/dolibarr-cli/schema/'.$schema.'.schema.json');
    if ( ! $result->isValid()) {
        $error = $result->error();
        $formatter = new Opis\JsonSchema\Errors\ErrorFormatter();
        throw new RuntimeException("JSON does not match {$schema}.schema.json:\n".print_r(null === $error ? [] : $formatter->format($error), true)."\n".$json);
    }
}

/**
 * @param  array<int, string>  $cmd
 * @param  array<string, string>  $env
 * @return array{code: int, out: string, err: string}
 */
function runProcess(array $cmd, ?string $stdin, array $env, string $cwd): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env);
    if ( ! is_resource($process)) {
        throw new RuntimeException('Cannot start '.implode(' ', $cmd));
    }
    if (null !== $stdin) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);

    // Read stdout and stderr together: reading one to its end first deadlocks as soon as the
    // other fills its pipe buffer (the progress of a long command on stderr).
    $buffers = [1 => '', 2 => ''];
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    foreach ($open as $pipe) {
        stream_set_blocking($pipe, false);
    }
    while ([] !== $open) {
        $read = array_values($open);
        $write = null;
        $except = null;
        if (false === stream_select($read, $write, $except, 5)) {
            break;
        }
        foreach ($open as $index => $pipe) {
            $buffers[$index] .= (string) fread($pipe, 65536);
            if (feof($pipe)) {
                fclose($pipe);
                unset($open[$index]);
            }
        }
    }

    return ['code' => proc_close($process), 'out' => $buffers[1], 'err' => $buffers[2]];
}
