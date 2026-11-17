<?php

declare(strict_types=1);

use Dolifleet\Console\Lang\Translator;
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

function dashboard(string $lang = 'en'): Dashboard
{
    return new Dashboard('secret-token', 8765, DOLICLI_TEST_TMP.'/fleet/inventory.yaml', DOLICLI_ROOT.'/fleet/bin/dolifleet', new Translator($lang));
}

/**
 * @param  array<string, string>  $server
 * @return array<string, string>
 */
function request(string $uri, array $server = []): array
{
    return array_merge(['HTTP_HOST' => '127.0.0.1:8765', 'REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET'], $server);
}

function dashboardPage(string $uri, string $lang = 'en'): string
{
    return dashboard($lang)->handle(request($uri), [], [], [Dashboard::COOKIE => dashboard()->session()])->body;
}

/**
 * dev1 (protected) without problem, dev2 critical: the fleet shows dev2 first.
 */
function dashboardStates(): void
{
    $now = time();
    (new StateStore())->record('dev1', ['status' => 'ok', 'generated_at_unix' => $now, 'checks' => [
        'dolibarr.version' => ['status' => 'ok', 'value' => '20.0.6', 'collected_at' => date('c', $now)],
        'php.cli_version' => ['status' => 'ok', 'value' => '8.2.21'],
        'maintenance.enabled' => ['status' => 'ok', 'value' => false],
    ]]);
    (new StateStore())->record('dev2', ['status' => 'critical', 'generated_at_unix' => $now - 120, 'checks' => [
        'dolibarr.version' => ['status' => 'ok', 'value' => '18.0.11'],
        'backup.age' => ['status' => 'critical', 'value' => 9 * 86400 + 5, 'message' => 'Last backup 9 days ago', 'collected_at' => date('c', $now - 120)],
        'cron.failed' => ['status' => 'warning', 'value' => 2, 'message' => '2 jobs failed'],
        'maintenance.enabled' => ['status' => 'warning', 'value' => true],
    ]]);
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
        ->and($detail->body)->not->toContain('<img')
        ->and($fleet->headers['Content-Security-Policy'])->toBe("default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
        ->and($detail->headers['Content-Security-Policy'])->toBe($fleet->headers['Content-Security-Policy'])
        ->and($fleet->headers['X-Content-Type-Options'])->toBe('nosniff')
        ->and($fleet->body)->not->toContain('http://')
        ->and($fleet->body)->not->toContain('https://')
        ->and($fleet->body)->not->toMatch('/<script(?! src="\/app\.js" defer><\/script>)/')
        ->and($fleet->body)->not->toContain(' style=')
        ->and($fleet->body)->not->toContain(' onclick=');
});

it('shows the fleet like the console: columns, worst first, signs and words, PROD badge, command', function (): void {
    dashboardStates();
    $body = dashboardPage('/');
    $rows = (string) mb_strstr($body, '<tbody>');

    expect($body)->toContain('<html lang="en">')
        ->and($body)->toContain('<span class="prompt">$ </span><code>dolifleet status all</code>')
        ->and($body)->toMatch('#<th class="p0">instance</th><th class="p3">version</th><th class="p5">php</th><th class="p0">state</th><th class="p4">backup</th><th class="p6">maint\.</th><th class="p2">to look at</th>#')
        ->and(mb_strpos($rows, '/instance/dev2'))->toBeLessThan((int) mb_strpos($rows, '/instance/dev1'))
        ->and($body)->toContain('<span class="st-crit">!! CRIT.</span>')
        ->and($body)->toContain('<span class="st-crit">no backup for 9 d</span><span class="st-dim"> (+2)</span>')
        ->and($body)->toContain('<span class="st-warn">ON</span>')
        ->and($body)->toContain('<td class="p5">8.2</td>')
        ->and($body)->toContain('dev1</a> <span class="prod">PROD</span>')
        ->and($body)->not->toContain('dev2</a> <span class="prod">')
        ->and($body)->toContain('2 instances   state ')
        ->and($body)->toContain('title="state 2 min ago"');
});

it('renders the dashboard in French with the texts of the console', function (): void {
    dashboardStates();
    $fleet = dashboardPage('/', 'fr');
    $card = dashboardPage('/instance/dev2', 'fr');

    expect($fleet)->toContain('<html lang="fr">')
        ->and($fleet)->toContain('<th class="p2">à regarder</th>')
        ->and($fleet)->toContain('<th class="p4">sauveg.</th>')
        ->and($fleet)->toContain('>Parc</a>')
        ->and($fleet)->toContain('&gt; </span>tous</h1>')
        ->and($fleet)->toContain('aucune sauvegarde depuis 9 j')
        ->and($fleet)->toContain('>Vérifier à nouveau</button>')
        ->and($fleet)->toContain('2 instances   état il y a ')
        ->and($fleet)->not->toContain('to look at')
        ->and($card)->toContain('<h2>Supervision</h2>')
        ->and($card)->toContain('Dernière sauvegarde')
        ->and($card)->toContain('message de dolicli')
        ->and($card)->toContain('<th scope="row">Sauvegardes</th>')
        ->and($card)->toContain('2 tâches planifiées en échec')
        ->and($card)->toContain('<code>dolifleet log dev2</code>');
});

it('shows the card of an instance with its summary, checks and journal, each with its command', function (): void {
    dashboardStates();
    $body = dashboardPage('/instance/dev2');
    $prod = dashboardPage('/instance/dev1');

    expect($body)->toContain('<code>dolifleet status dev2</code>')
        ->and($body)->toContain('<h2>Monitoring</h2><p class="command" title="Same screen on the command line:"><span class="prompt">$ </span><code>dolifleet exec dev2 -- monitor show</code>')
        ->and($body)->toContain('<h2>Journal</h2><p class="command" title="Same screen on the command line:"><span class="prompt">$ </span><code>dolifleet log dev2</code>')
        ->and($body)->toContain('<th scope="row">Backups</th><td><span class="st-crit">last one 9 d ago</span></td>')
        ->and($body)->toContain('<th scope="row">Server</th>')
        ->and($body)->toContain('<td class="p0">Last backup</td><td class="p0 state"><span class="st-crit">!! CRIT.</span></td><td class="p1">9 d</td><td class="p3">2 min ago</td><td class="p2 st-dim">Last backup 9 days ago</td>')
        ->and($body)->toContain('<span class="st-warn">!  WARN.</span>')
        ->and($body)->toContain('<h3>To look at</h3>')
        ->and($body)->toContain('!! CRIT.   state 2 min ago')
        ->and($body)->not->toContain('class="prod"')
        ->and($prod)->toContain('<body class="prod"')
        ->and($prod)->toContain('dev1 <span class="prod">PROD</span></h1>')
        ->and(dashboard()->handle(request('/instance/nope'), [], [], [Dashboard::COOKIE => dashboard()->session()])->status)->toBe(404);
});

it('offers the actions with the confirmation of the console, maintenance first for the phone', function (): void {
    dashboardStates();
    $body = dashboardPage('/');

    expect($body)->toContain('class="action maintenance" data-action="maintenance-off" data-target="dev2" data-mode="confirm"')
        ->and($body)->toContain('class="action maintenance" data-action="maintenance-on" data-target="dev1" data-mode="type"')
        ->and($body)->toContain('data-question="Maintenance mode on: only the maintenance login can log in.'."\n\n".'To confirm, type the name of the instance (dev1):"')
        ->and($body)->toContain('data-question="Maintenance mode off: every user can log in again.'."\n\n".'Maintenance off on dev2?"')
        ->and($body)->toContain('data-action="collect" data-target="dev2" data-mode="none"')
        ->and($body)->toContain('<meta name="viewport" content="width=device-width, initial-scale=1">')
        ->and((string) file_get_contents(DOLICLI_ROOT.'/fleet/web/app.css'))->toContain('button.action:not(.maintenance) { display: none; }');
});

it('accepts actions only as POST with the token header, with the guards of the CLI', function (): void {
    $cookie = [Dashboard::COOKIE => dashboard()->session()];
    $post = fn (array $fields, array $server = [], string $lang = 'en') => dashboard($lang)->handle(request('/action', array_merge(['REQUEST_METHOD' => 'POST'], $server)), [], $fields, $cookie);
    $header = ['HTTP_X_DOLIFLEET_TOKEN' => dashboard()->csrf()];

    expect($post(['action' => 'maintenance-on', 'target' => 'dev2'])->status)->toBe(403)
        ->and($post(['action' => 'rm', 'target' => 'dev2'], $header)->status)->toBe(400)
        ->and($post(['action' => 'collect', 'target' => 'nope'], $header)->status)->toBe(400)
        ->and($post(['action' => 'maintenance-on', 'target' => 'dev1'], $header)->status)->toBe(409)
        ->and($post(['action' => 'maintenance-on', 'target' => 'dev1', 'confirm' => 'yes'], $header)->status)->toBe(409)
        ->and($post(['action' => 'backup', 'target' => 'dev2'], $header)->status)->toBe(409)
        ->and($post(['action' => 'backup', 'target' => 'dev2'], $header, 'fr')->body)->toContain('Ce n\'est pas la réponse attendue')
        ->and($post(['action' => 'collect', 'target' => 'dev2'], $header)->status)->toBe(202)
        ->and(glob(DOLICLI_TEST_TMP.'/fleet/data/dolifleet/serve-tasks/*-collect-dev2.log'))->toHaveCount(1)
        ->and(dashboardPage('/tasks'))->toContain('<code>dolifleet status dev2 --refresh</code>');
});

it('builds the command of a write with --confirm and the dashboard as front end, never --yes', function (): void {
    $protected = dashboard()->actionCommand('maintenance-on', 'dev1', Dashboard::expectedAnswer(true, 'dev1'));
    $plain = dashboard()->actionCommand('backup', 'dev2', Dashboard::expectedAnswer(false, 'dev2'));
    $read = dashboard()->actionCommand('collect', 'dev2', '');

    expect($protected['command'])->toContain('--confirm=dev1')
        ->and($protected['shown'])->toBe(['maintenance', 'on', 'dev1'])
        ->and($plain['command'])->toContain('--confirm=yes')
        ->and($plain['shown'])->toBe(['backup', 'dev2', '--database', '--documents'])
        ->and(array_merge($protected['command'], $plain['command'], $read['command']))->not->toContain('--yes')
        ->and(array_merge($protected['command'], $plain['command'], $read['command']))->not->toContain('-y')
        ->and(implode(' ', $read['command']))->not->toContain('--confirm')
        ->and($protected['env']['DOLIFLEET_FRONTEND'])->toBe('dashboard')
        ->and($protected['env']['DOLIFLEET_INVENTORY'])->toBe(DOLICLI_TEST_TMP.'/fleet/inventory.yaml');
});

it('runs a confirmed write that the journal records as coming from the dashboard', function (): void {
    $env = ['DOLIFLEET_SSH' => DOLICLI_ROOT.'/tests/Fleet/fake-ssh', 'DOLIFLEET_BODY' => DOLICLI_TEST_DIST.'/dolicli.php', 'XDG_CONFIG_HOME' => DOLICLI_TEST_TMP.'/fleet/config'];
    foreach ($env as $name => $value) {
        putenv($name.'='.$value);
    }
    try {
        $cookie = [Dashboard::COOKIE => dashboard()->session()];
        $response = dashboard()->handle(request('/action', ['REQUEST_METHOD' => 'POST', 'HTTP_X_DOLIFLEET_TOKEN' => dashboard()->csrf()]), [], ['action' => 'maintenance-on', 'target' => 'dev2', 'confirm' => 'yes'], $cookie);
        $task = (string) (json_decode($response->body, true)['task'] ?? '');
        $exit = Dashboard::tasksDir().'/'.$task.'.exit';
        for ($i = 0; $i < 300 && ! is_file($exit); ++$i) {
            usleep(100000);
        }
        $tasks = dashboardPage('/tasks');
    } finally {
        foreach (array_keys($env) as $name) {
            putenv($name);
        }
    }
    $mine = array_values(array_filter(fleetJournal(), static fn (array $entry): bool => 'dashboard' === ($entry['via'] ?? null)));
    $off = fleet(['maintenance', 'off', 'dev2', '--confirm=yes']);

    expect($response->status)->toBe(202)
        ->and(is_file($exit))->toBeTrue()
        ->and(trim((string) file_get_contents($exit)))->toBe('0', (string) @file_get_contents(Dashboard::tasksDir().'/'.$task.'.log'))
        ->and($mine)->not->toBe([])
        ->and(end($mine)['phase'])->toBe('intention')
        ->and(end($mine)['command'])->toBe('system maintenance enable')
        ->and(end($mine)['yes'])->toBeFalse()
        ->and($tasks)->toContain('<code>dolifleet maintenance on dev2</code>')
        ->and($tasks)->toContain('<span class="state">   done</span>')
        ->and($off['code'])->toBe(0);
});

it('takes every text it shows from the language files, written with accents and ASCII punctuation', function (): void {
    $reference = include Translator::defaultDirectory().'/en.php';
    $french = include Translator::defaultDirectory().'/fr.php';
    $used = [];
    foreach ([DOLICLI_ROOT.'/fleet/src/Web/Dashboard.php', DOLICLI_ROOT.'/fleet/src/Command/ServeCommand.php', DOLICLI_ROOT.'/fleet/web/router.php'] as $file) {
        preg_match_all("/->t\\('([a-z0-9_.-]+)'\\s*[,)]/", (string) file_get_contents($file), $m);
        $used = array_merge($used, $m[1]);
    }
    $web = array_filter($french, static fn (string $key): bool => str_starts_with($key, 'web.'), ARRAY_FILTER_USE_KEY);

    expect($used)->not->toBe([])
        ->and(array_values(array_diff(array_unique($used), array_keys($reference))))->toBe([])
        ->and(array_values(array_filter($web, static fn (string $text): bool => 1 === preg_match('/[\x{00A0}\x{00AB}\x{00BB}\x{2000}-\x{206F}]/u', $text))))->toBe([])
        ->and(implode("\n", $web))->toContain('Échec')
        ->and((string) file_get_contents(DOLICLI_ROOT.'/fleet/web/app.js'))->not->toMatch('/(alert|confirm|prompt)\\(\'/');
});

it('serves the dashboard on 127.0.0.1 with the token printed in the terminal', function (): void {
    $env = serverEnvironment([
        'DOLIFLEET_INVENTORY' => DOLICLI_TEST_TMP.'/fleet/inventory.yaml',
        'XDG_DATA_HOME' => DOLICLI_TEST_TMP.'/fleet/data',
        'DOLIFLEET_BODY' => DOLICLI_TEST_DIST.'/dolicli.php',
    ]);
    $process = new Process([PHP_BINARY, DOLICLI_ROOT.'/fleet/bin/dolifleet', 'serve', '--lang=fr'], DOLICLI_ROOT, $env, null, null);
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
        ->and($page)->toContain('<html lang="fr">')
        ->and($page)->toContain('<code>dolifleet status all</code>')
        ->and($page)->toContain('dev1')
        ->and($process->getOutput())->toContain('Tableau de bord : http://127.0.0.1:')
        ->and($process->getOutput())->toContain('ssh -L ')
        ->and($process->getCommandLine())->not->toContain('token');
});
