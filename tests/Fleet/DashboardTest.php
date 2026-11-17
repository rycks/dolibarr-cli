<?php

declare(strict_types=1);

use Dolifleet\Store\StateStore;
use Dolifleet\Web\Dashboard;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    fleetInventory();
    putenv('XDG_DATA_HOME='.DOLICLI_TEST_TMP.'/fleet/data');
});

afterEach(function (): void {
    putenv('XDG_DATA_HOME');
});

function dashboard(): Dashboard
{
    return new Dashboard('secret-token', 8765, DOLICLI_TEST_TMP.'/fleet/inventory.yaml', DOLICLI_ROOT.'/fleet/bin/dolifleet');
}

/**
 * @param  array<string, string>  $server
 * @return array<string, string>
 */
function request(string $uri, array $server = []): array
{
    return array_merge(['HTTP_HOST' => '127.0.0.1:8765', 'REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET'], $server);
}

it('exchanges the token for an HttpOnly SameSite cookie and redirects without it', function (): void {
    $response = dashboard()->handle(request('/?token=secret-token'), ['token' => 'secret-token'], [], []);
    $bad = dashboard()->handle(request('/?token=nope'), ['token' => 'nope'], [], []);
    $anonymous = dashboard()->handle(request('/'), [], [], []);

    expect($response->status)->toBe(303)
        ->and($response->headers['Location'])->toBe('/')
        ->and($response->headers['Set-Cookie'])->toContain('HttpOnly; SameSite=Strict')
        ->and($response->headers['Set-Cookie'])->not->toContain('secret-token')
        ->and($bad->status)->toBe(403)
        ->and($anonymous->status)->toBe(401);
});

it('refuses other hosts and other origins (DNS rebinding)', function (): void {
    $cookie = [Dashboard::COOKIE => dashboard()->session()];

    expect(dashboard()->handle(request('/', ['HTTP_HOST' => 'evil.example:8765']), [], [], $cookie)->status)->toBe(421)
        ->and(dashboard()->handle(request('/', ['HTTP_ORIGIN' => 'http://evil.example']), [], [], $cookie)->status)->toBe(403)
        ->and(dashboard()->handle(request('/'), [], [], $cookie)->status)->toBe(200);
});

it('escapes the data received from the servers and sets a strict CSP', function (): void {
    (new StateStore())->record('dev1', ['status' => 'warning', 'generated_at_unix' => time(), 'checks' => [
        'dolibarr.version' => ['status' => 'ok', 'value' => '<script>alert(1)</script>', 'message' => '"><img src=x onerror=alert(2)>'],
    ]]);
    $cookie = [Dashboard::COOKIE => dashboard()->session()];

    $fleet = dashboard()->handle(request('/'), [], [], $cookie);
    $detail = dashboard()->handle(request('/instance/dev1'), [], [], $cookie);

    expect($fleet->body)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($fleet->body)->not->toContain('<script>alert')
        ->and($detail->body)->toContain('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;')
        ->and($fleet->headers['Content-Security-Policy'])->toContain("default-src 'none'")
        ->and($fleet->headers['Content-Security-Policy'])->toContain("script-src 'self'")
        ->and($fleet->body)->not->toContain('http://')
        ->and($fleet->body)->not->toContain('https://');
});

it('accepts actions only as POST with the token header, with the guards of the CLI', function (): void {
    $cookie = [Dashboard::COOKIE => dashboard()->session()];
    $post = fn (array $fields, array $server = []) => dashboard()->handle(request('/action', array_merge(['REQUEST_METHOD' => 'POST'], $server)), [], $fields, $cookie);
    $header = ['HTTP_X_DOLIFLEET_TOKEN' => dashboard()->csrf()];

    expect($post(['action' => 'maintenance-on', 'target' => 'dev2'])->status)->toBe(403)
        ->and($post(['action' => 'rm', 'target' => 'dev2'], $header)->status)->toBe(400)
        ->and($post(['action' => 'collect', 'target' => 'nope'], $header)->status)->toBe(400)
        ->and($post(['action' => 'maintenance-on', 'target' => 'dev1'], $header)->status)->toBe(409)
        ->and($post(['action' => 'collect', 'target' => 'dev2'], $header)->status)->toBe(202)
        ->and(glob(DOLICLI_TEST_TMP.'/fleet/data/dolifleet/serve-tasks/*-collect-dev2.log'))->toHaveCount(1);
});

it('serves the dashboard on 127.0.0.1 with the token printed in the terminal', function (): void {
    $env = serverEnvironment([
        'DOLIFLEET_INVENTORY' => DOLICLI_TEST_TMP.'/fleet/inventory.yaml',
        'XDG_DATA_HOME' => DOLICLI_TEST_TMP.'/fleet/data',
        'DOLIFLEET_BODY' => DOLICLI_TEST_DIST.'/dolicli.php',
    ]);
    $process = new Process([PHP_BINARY, DOLICLI_ROOT.'/fleet/bin/dolifleet', 'serve'], DOLICLI_ROOT, $env, null, null);
    $process->start();
    try {
        $url = '';
        for ($i = 0; $i < 50 && '' === $url; ++$i) {
            usleep(100000);
            preg_match('#http://127\.0\.0\.1:\d+/\?token=[0-9a-f]+#', $process->getOutput(), $m);
            $url = $m[0] ?? '';
        }
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]);
        $first = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $cookie = '';
        foreach ($headers as $header) {
            if (1 === preg_match('/^Set-Cookie: ([^;]+)/i', $header, $c)) {
                $cookie = $c[1];
            }
        }
        $page = file_get_contents(preg_replace('#\?token=.*$#', '', $url), false, stream_context_create(['http' => ['header' => 'Cookie: '.$cookie]]));
    } finally {
        $process->stop(1);
    }

    expect($url)->not->toBe('')
        ->and($first)->toBe('')
        ->and($headers[0] ?? '')->toContain('303')
        ->and($page)->toContain('<h1>Fleet</h1>')
        ->and($page)->toContain('dev1')
        ->and($process->getCommandLine())->not->toContain('token');
});
