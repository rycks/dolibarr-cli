<?php

declare(strict_types=1);

/**
 * Run the dolicli entry point in a subprocess against the SQLite test Dolibarr.
 *
 * @param  array<int, string>  $args  Command line arguments (without the script name)
 * @param  array<string, string|false>  $env  Extra environment variables (false removes one)
 * @return array{code: int, out: string, err: string}
 */
function dolicli(array $args, ?string $stdin = null, array $env = []): array
{
    $baseEnv = getenv();
    $baseEnv['DOLPATH'] = DOLICLI_DOLPATH;
    $baseEnv['NO_COLOR'] = '1';
    foreach ($env as $name => $value) {
        if (false === $value) {
            unset($baseEnv[$name]);
        } else {
            $baseEnv[$name] = $value;
        }
    }

    $cmd = array_merge([PHP_BINARY, DOLICLI_ROOT.'/dolibarr'], $args);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, DOLICLI_ROOT, $baseEnv);
    if ( ! is_resource($process)) {
        throw new RuntimeException('Cannot start dolicli subprocess');
    }

    if (null !== $stdin) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => $code,
        'out' => stripAnsi($out),
        'err' => stripAnsi($err),
    ];
}

function stripAnsi(string $text): string
{
    return (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
}

/**
 * Direct access to the SQLite test database, to check what a command wrote.
 */
function testDb(): PDO
{
    $pdo = new PDO('sqlite:'.DOLICLI_TEST_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

/**
 * @param  array<int|string, mixed>  $params
 */
function dbValue(string $sql, array $params = []): mixed
{
    $stmt = testDb()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function restoreTestDb(): void
{
    if ( ! copy(DOLICLI_TEST_DB_BACKUP, DOLICLI_TEST_DB)) {
        throw new RuntimeException('Cannot restore the SQLite Dolibarr database');
    }
}
