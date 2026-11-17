<?php

declare(strict_types=1);

dataset('php', fn (): array => phpMatrix());

it('reports its version under every PHP of the matrix', function (string $php): void {
    $result = serverJson(['version'], null, [], $php);
    $text = server(['version', '--format=text'], null, [], $php);

    expect($result['code'])->toBe(0)
        ->and($result['json']['ok'])->toBeTrue()
        ->and($result['json']['command'])->toBe('version')
        ->and($result['json']['instance'])->toBeNull()
        ->and($result['json']['data']['dolicli_version'])->toBe('test')
        ->and($result['json']['data']['php_binary'])->toBe($php)
        ->and($result['json']['data']['body_sha256'])->toBe(ServerBuilder::bodyHash((string) file_get_contents(DOLICLI_TEST_DIST.'/dolicli.php')))
        ->and($text['code'])->toBe(0)
        ->and($text['out'])->toStartWith('dolicli test');
})->with('php');

it('runs from the standard input with its arguments in the stream (ephemeral mode)', function (string $php): void {
    $result = serverEphemeral(['version', '--format=json'], [], $php);
    $json = json_decode($result['out'], true);

    expect($result['code'])->toBe(0)
        ->and($result['err'])->toBe('')
        ->and($json['data']['php_binary'] ?? null)->toBe($php);
    assertMatchesSchema($result['out'], 'envelope');
})->with('php');

it('refuses a PHP older than 7.0 from the launcher with exit code 3', function (): void {
    $result = server(['version'], null, [], '/usr/bin/php5.6');

    expect($result['code'])->toBe(3)
        ->and($result['err'])->toContain('dolicli needs PHP 7.0 or later');
})->skip( ! is_executable('/usr/bin/php5.6'), 'php5.6 is not installed on this machine');

it('writes JSON when stdout is not a terminal and text on request', function (): void {
    $json = server(['version']);
    $text = server(['version', '--format=text']);

    expect(json_decode($json['out'], true)['command'] ?? null)->toBe('version')
        ->and($text['out'])->toStartWith('dolicli test');
});

it('exits with 2 on usage errors, with a stable error code', function (array $args, string $code): void {
    $result = serverJson($args);

    expect($result['code'])->toBe(2)
        ->and($result['json']['ok'])->toBeFalse()
        ->and($result['json']['errors'][0]['code'])->toBe($code);
})->with([
    'unknown command' => [['nosuch'], 'UNKNOWN_COMMAND'],
    'unknown parameter' => [['version', '--bogus'], 'UNKNOWN_PARAMETER'],
    'unexpected word' => [['version', 'extra'], 'UNEXPECTED_ARGUMENT'],
    'invalid entity' => [['version', '--entity=abc'], 'INVALID_VALUE'],
    'instance and dolpath' => [['env', 'doctor', '--instance=a', '--dolpath=/tmp'], 'INSTANCE_AMBIGUOUS'],
]);

it('rejects an invalid format in text', function (): void {
    $result = server(['version', '--format=xml']);

    expect($result['code'])->toBe(2)
        ->and($result['err'])->toContain("Invalid format 'xml'");
});

it('shows the detailed help of a command, and the commands of a group', function (): void {
    $command = server(['env', 'doctor', '--help', '--format=text']);
    $group = server(['env', '--format=text']);
    $general = server(['--format=text']);

    expect($command['code'])->toBe(0)
        ->and($command['out'])->toContain('Usage:')
        ->and($command['out'])->toContain('dolicli env doctor')
        ->and($group['code'])->toBe(0)
        ->and($group['out'])->toContain('Commands of env:')
        ->and($general['out'])->toContain('Exit codes:')
        ->and($general['out'])->toContain('version');
});

it('publishes a manifest of the commands', function (): void {
    $result = serverJson(['help']);
    $commands = array_column($result['json']['data']['commands'], null, 'name');

    expect($result['code'])->toBe(0)
        ->and($commands)->toHaveKey('version')
        ->and($commands['env doctor']['category'])->toBe('read')
        ->and($commands['env doctor']['needs_dolibarr'])->toBeFalse()
        ->and(array_column($result['json']['data']['global_options'], 'name'))->toContain('dry-run')
        ->and(array_column($result['json']['data']['exit_codes'], 'code'))->toBe([0, 1, 2, 3, 4, 5, 6]);
});

it('checks the environment of an instance without loading Dolibarr', function (): void {
    $result = serverJson(['env', 'doctor']);
    $checks = array_column($result['json']['data']['checks'], null, 'check');

    expect($result['json']['instance']['id'])->toBe('dolibarr-integration-sqlite')
        ->and($checks['instance.version']['value'])->toBe('18.0.10')
        ->and($checks['documents.path']['value'])->toBe(realpath(DOLICLI_TEST_DOCUMENTS))
        ->and($checks['conf.readable']['status'])->toBe('ok')
        ->and($checks['php.json']['status'])->toBe('ok');
});

it('skips the instance checks when no Dolibarr is found', function (): void {
    $result = serverJson(['env', 'doctor'], null, ['DOLPATH' => '/nonexistent/htdocs']);
    $checks = array_column($result['json']['data']['checks'], null, 'check');

    expect($checks['instance']['status'])->toBe('warning');
});
