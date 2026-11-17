<?php

declare(strict_types=1);

use Dolifleet\Console\App;
use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Operation;
use Dolifleet\Console\Screen\BackstageScreen;
use Dolifleet\Console\Screen\ErrorScreen;
use Dolifleet\Console\Screen\FleetScreen;
use Dolifleet\Console\Screen\InstanceScreen;
use Dolifleet\Console\Screen\OperationsScreen;
use Dolifleet\Console\Screen\PaletteScreen;
use Dolifleet\Console\Screen\PreviewScreen;
use Dolifleet\Console\Screen\PromptScreen;
use Dolifleet\Console\Screen\ResultScreen;
use Dolifleet\Console\Screen\WelcomeScreen;
use Dolifleet\Console\Settings;
use Dolifleet\Console\Tasks\Backend;
use Dolifleet\Console\Tasks\CliBackend;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Renderer;
use Dolifleet\Console\Terminal\Style;
use Dolifleet\Store\StateStore;

/**
 * Backend of the tests: records the commands, the test finishes them with the JSON it wants.
 */
final class ConsoleFakeBackend implements Backend
{
    /** @var list<Task> */
    public array $tasks = [];

    /** @var list<Task> */
    private array $finished = [];

    /** @var array<string, list<string>> task id => options added at run time */
    public array $options = [];

    public function start(array $args, string $purpose, array $instances, bool $write, array $options = [], array $meta = []): Task
    {
        $task = new Task('t'.count($this->tasks), $args, $purpose, $instances, $write, microtime(true), $meta);
        $this->tasks[] = $task;
        $this->options[$task->id] = $options;

        return $task;
    }

    /**
     * @param array<mixed>|null $json
     */
    public function finish(Task $task, ?array $json, int $exitCode = 0, string $stderr = ''): void
    {
        $task->exitCode = $exitCode;
        $task->json = $json;
        $task->stdout = (string) json_encode($json);
        $task->stderr = $stderr;
        $task->endedAt = microtime(true);
        $this->finished[] = $task;
    }

    public function poll(): array
    {
        $finished = $this->finished;
        $this->finished = [];

        return $finished;
    }

    public function all(): array
    {
        return $this->tasks;
    }

    public function runningReads(): int
    {
        return count(array_filter($this->tasks, static fn (Task $t): bool => ! $t->write && ! $t->finished()));
    }

    /**
     * Last task whose command starts with these words.
     *
     * @param list<string> $words
     */
    public function last(array $words): ?Task
    {
        foreach (array_reverse($this->tasks) as $task) {
            if (array_slice($task->args, 0, count($words)) === $words) {
                return $task;
            }
        }

        return null;
    }
}

/**
 * @return array{0: App, 1: ConsoleFakeBackend}
 */
function consoleApp(string $lang = 'fr'): array
{
    fleetInventory();
    $dir = DOLICLI_TEST_TMP.'/console-'.bin2hex(random_bytes(3));
    mkdir($dir, 0700, true);
    $store = new StateStore($dir.'/state.sqlite');
    $store->record('dev1', [
        'generated_at_unix' => time() - 120,
        'status' => 'critical',
        'instance' => ['url' => 'https://erp.example.com'],
        'checks' => [
            'dolibarr.version' => ['status' => 'ok', 'value' => '20.0.1'],
            'php.cli_version' => ['status' => 'ok', 'value' => '8.2.28'],
            'backup.age' => ['status' => 'critical', 'value' => 9 * 86400 + 100, 'message' => 'Last backup x.sql, 9.0 days ago'],
            'maintenance.enabled' => ['status' => 'ok', 'value' => false],
        ],
    ]);
    $store->record('dev2', [
        'generated_at_unix' => time() - 300,
        'status' => 'ok',
        'checks' => [
            'dolibarr.version' => ['status' => 'ok', 'value' => '19.0.3'],
            'backup.age' => ['status' => 'ok', 'value' => 3600],
            'maintenance.enabled' => ['status' => 'ok', 'value' => false],
        ],
    ]);
    $backend = new ConsoleFakeBackend();
    $app = new App(new Translator($lang), new Style(false, false), $backend, $store, DOLICLI_TEST_TMP.'/fleet/inventory.yaml', Settings::load($dir.'/settings.yaml'));
    $app->push(new FleetScreen());

    return [$app, $backend];
}

/**
 * @return list<string>
 */
function screenOf(App $app, int $cols = 80, int $rows = 24): array
{
    return Renderer::plain($app->frame($cols, $rows), $cols);
}

/**
 * @param list<string> $keys
 */
function press(App $app, array $keys): void
{
    foreach ($keys as $key) {
        $app->key($key);
    }
}

function typeText(App $app, string $text): void
{
    foreach (mb_str_split($text) as $char) {
        $app->key(' ' === $char ? 'space' : $char);
    }
}

it('shows the fleet worst first, in 80 columns, in French and in English', function (): void {
    [$app] = consoleApp('fr');
    $french = screenOf($app);
    [$english] = consoleApp('en');
    $lines = screenOf($english);

    expect($french[0])->toContain('dolifleet > tous')
        ->and($french[0])->toContain('2 instances')
        ->and($french[1])->toBe(' $ dolifleet status all')
        ->and($french[4])->toContain('dev1 PROD')
        ->and($french[4])->toContain('!! CRIT.')
        ->and($french[4])->toContain('aucune sauvegarde depuis 9 j')
        ->and($french[5])->toContain('dev2')
        ->and($french[23])->toContain('b sauvegarde')
        ->and($lines[4])->toContain('no backup for 9 d')
        ->and($lines[23])->toContain('Enter card');
    foreach (array_merge($french, $lines) as $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual(80);
    }
});

it('refreshes the instances in the background, line by line, and shows the unreachable ones', function (): void {
    [$app, $backend] = consoleApp();
    $app->refresh(['dev1', 'dev2']);
    $app->tick();
    $refreshes = array_values(array_filter($backend->tasks, static fn (Task $t): bool => 'refresh' === $t->purpose));
    expect(array_map(static fn (Task $t): array => $t->args, $refreshes))->toBe([['status', 'dev1'], ['status', 'dev2']])
        ->and(screenOf($app)[4])->toContain('.. en cours');

    $backend->finish($refreshes[0], ['instances' => ['dev1' => ['ok' => false, 'error' => 'SSH_FAILED: connection refused']]], 3);
    $app->tick();

    expect(screenOf($app)[4])->toContain('?  injoignable')
        ->and(screenOf($app)[4])->toContain('SSH_FAILED');
});

it('filters on a tag, shows the matching command and the checked count', function (): void {
    [$app] = consoleApp();
    press($app, ['/']);
    typeText($app, 'tag:preprod');
    press($app, ['enter', 'space']);
    $screen = screenOf($app);

    expect($screen[1])->toBe(' $ dolifleet status tag:preprod')
        ->and($screen[0])->toContain('1 instances')
        ->and($screen[0])->toContain('1 cochées')
        ->and(implode("\n", $screen))->not->toContain('dev1');
});

it('previews a batch with production, asks the number of production instances and starts the write', function (): void {
    [$app, $backend] = consoleApp();
    press($app, ['space', 'space', 'm']);
    expect($app->top())->toBeInstanceOf(PreviewScreen::class);
    screenOf($app);
    $simulation = $backend->last(['maintenance', 'on', 'dev1,dev2']);
    expect($simulation?->write)->toBeFalse()
        ->and($backend->options[$simulation?->id ?? ''])->toBe(['--dry-run']);

    $backend->finish($simulation, ['instances' => [
        'dev1' => ['ok' => true, 'envelope' => ['data' => ['dry_run' => true, 'actions' => ["Enable the maintenance mode for 'admin'"]]]],
        'dev2' => ['ok' => true, 'envelope' => ['data' => ['dry_run' => true, 'actions' => ["Enable the maintenance mode for 'admin'"]]]],
    ]]);
    $app->tick();
    $preview = implode("\n", screenOf($app));
    expect($preview)->toContain('Simulation de dolicli')
        ->and($preview)->toContain("Enable the maintenance mode for 'admin'")
        ->and($preview)->toContain('dev1 d\'abord (témoin)')
        ->and($preview)->toContain('tapez le nombre d\'instances de production');

    press($app, ['2', 'enter']);
    expect(implode("\n", screenOf($app)))->toContain('rien n\'a été fait')
        ->and(array_filter($backend->tasks, static fn (Task $t): bool => $t->write))->toBe([]);

    press($app, ['1', 'enter']);
    $write = $backend->last(['maintenance', 'on', 'dev1,dev2']);
    expect($write?->write)->toBeTrue()
        ->and($backend->options[$write?->id ?? ''])->toBe(['--confirm=dev1,dev2'])
        ->and($app->top())->toBeInstanceOf(FleetScreen::class)
        ->and(implode("\n", screenOf($app)))->toContain('opérations');

    $backend->finish($write, ['instances' => ['dev1' => ['ok' => true], 'dev2' => ['ok' => true]]]);
    $app->tick();
    expect($app->operations->all()[0]->state)->toBe(Operation::DONE)
        ->and($app->recap()[0]['command'])->toBe('dolifleet maintenance on dev1,dev2')
        ->and($app->recap()[0]['result'])->toBe('ok');
});

it('asks the name of a single production instance and Enter only outside production', function (): void {
    [$app, $backend] = consoleApp();
    press($app, ['b']);
    screenOf($app);
    $backend->finish($backend->last(['backup', 'dev1']), ['instances' => ['dev1' => ['ok' => true, 'envelope' => ['data' => ['dry_run' => true, 'actions' => ['Write /tmp/x-database.sql']]]]]]);
    $app->tick();
    expect(implode("\n", screenOf($app)))->toContain('tapez le nom de l\'instance (dev1)');
    press($app, ['enter']);
    expect(array_filter($backend->tasks, static fn (Task $t): bool => $t->write))->toBe([]);
    typeText($app, 'dev1');
    press($app, ['enter']);
    $write = $backend->last(['backup', 'dev1']);
    expect($write?->write)->toBeTrue()
        ->and($backend->options[$write?->id ?? ''])->toBe(['--confirm=dev1']);

    $backend->finish($write, ['instances' => ['dev1' => ['ok' => true, 'envelope' => ['data' => ['id' => 'job-1']]]]]);
    $app->tick();
    $follow = $backend->last(['jobs', 'dev1', '--id=job-1']);
    expect($follow)->not->toBeNull();
    $backend->finish($follow, ['instances' => ['dev1' => ['ok' => true, 'envelope' => ['data' => ['job' => ['state' => 'done', 'result' => ['ok' => true]]]]]]]);
    $app->tick();
    expect($app->operations->all()[0]->state)->toBe(Operation::DONE);

    [$other, $otherBackend] = consoleApp();
    press($other, ['down', 'm']);
    screenOf($other);
    $otherBackend->finish($otherBackend->last(['maintenance', 'on', 'dev2']), ['instances' => ['dev2' => ['ok' => true, 'envelope' => ['data' => ['actions' => ['x']]]]]]);
    $other->tick();
    expect(implode("\n", screenOf($other)))->toContain('Entrée exécute');
    press($other, ['enter']);
    expect($otherBackend->options[$otherBackend->last(['maintenance', 'on', 'dev2'])?->id ?? ''])->toBe(['--confirm=yes']);
});

it('upgrades from the plan, pauses at a manual step and resumes once the step is done', function (): void {
    [$app, $backend] = consoleApp();
    press($app, ['down', 'u']);
    expect($app->top())->toBeInstanceOf(PromptScreen::class);
    typeText($app, '19.0');
    press($app, ['enter']);
    expect(implode("\n", screenOf($app)))->toContain('X.Y.Z');
    typeText($app, '.5');
    press($app, ['enter']);
    screenOf($app);
    $plan = $backend->last(['upgrade', 'plan', 'dev2']);
    expect($plan?->args)->toBe(['upgrade', 'plan', 'dev2', '--to=19.0.5'])
        ->and($backend->options[$plan?->id ?? ''])->toBe([]);

    $backend->finish($plan, ['from' => '19.0.3', 'to' => '19.0.5', 'php_cli' => '/usr/bin/php8.2', 'blockers' => [], 'warnings' => ['web_php is not declared'],
        'steps' => [['id' => 'lease', 'type' => 'lease', 'description' => 'Lease'], ['id' => 'cron', 'type' => 'manual', 'description' => 'Stop cron']]]);
    $app->tick();
    $preview = implode("\n", screenOf($app));
    expect($preview)->toContain('Plan : 19.0.3 -> 19.0.5')
        ->and($preview)->toContain('tâches planifiées arrêtées sur le serveur (manuel)')
        ->and($preview)->toContain('web_php is not declared')
        ->and($preview)->toContain('tapez le nom de l\'instance (dev2)');

    typeText($app, 'dev2');
    press($app, ['enter']);
    $run = $backend->last(['upgrade', 'run', 'dev2']);
    expect($backend->options[$run?->id ?? ''])->toBe(['--confirm=dev2']);
    $backend->finish($run, ['state' => 'paused', 'plan' => ['steps' => [['id' => 'lease'], ['id' => 'cron', 'type' => 'manual']], 'progress' => ['lease' => ['state' => 'done'], 'cron' => ['state' => 'paused']]]]);
    $app->tick();
    $operation = $app->operations->all()[0];
    expect($operation->state)->toBe(Operation::PAUSED)
        ->and($operation->pausedStep)->toBe('cron');

    press($app, ['j']);
    expect($app->top())->toBeInstanceOf(OperationsScreen::class)
        ->and(implode("\n", screenOf($app)))->toContain('Étape manuelle à faire sur le serveur');
    press($app, ['d']);
    $resume = $backend->last(['upgrade', 'resume', 'dev2']);
    expect($backend->options[$resume?->id ?? ''])->toBe(['--confirm=dev2', '--done=cron'])
        ->and($operation->state)->toBe(Operation::RUNNING);
});

it('opens the card of an instance with numbered tabs, each one a dolicli command', function (): void {
    [$app, $backend] = consoleApp();
    press($app, ['enter']);
    expect($app->top())->toBeInstanceOf(InstanceScreen::class);
    $summary = screenOf($app);
    expect($summary[0])->toContain('dolifleet > dev1')
        ->and($summary[0])->toContain('PROD')
        ->and($summary[1])->toBe(' $ dolifleet status dev1')
        ->and(implode("\n", $summary))->toContain('1 Résumé')
        ->and(implode("\n", $summary))->toContain('https://erp.example.com')
        ->and(implode("\n", $summary))->toContain('aucune sauvegarde depuis 9 j');

    press($app, ['4']);
    $loading = screenOf($app);
    $cron = $backend->last(['exec', 'dev1', '--', 'cron', 'list']);
    expect($loading[1])->toBe(' $ dolifleet exec dev1 -- cron list')
        ->and($cron)->not->toBeNull();
    $backend->finish($cron, ['instances' => ['dev1' => ['ok' => true, 'envelope' => ['data' => ['jobs' => [
        ['id' => 3, 'label' => 'Send reminders', 'status' => 'enabled', 'frequency' => 'every 1 day', 'last_run' => '2026-11-15T06:00:00+01:00', 'last_result' => 'failed', 'next_run' => null, 'failed' => true, 'late' => false, 'stuck' => false],
    ]]]]]]);
    $app->tick();
    $tab = implode("\n", screenOf($app));
    expect($tab)->toContain('Send reminders')
        ->and($tab)->toContain('en échec')
        ->and($tab)->toContain('15/11/2026');

    press($app, ['2']);
    expect(screenOf($app)[1])->toBe(' $ dolifleet exec dev1 -- module list --external');
    press($app, ['x']);
    expect(screenOf($app)[1])->toBe(' $ dolifleet exec dev1 -- module list');
});

it('lists every command run in the backstage and explains a sudo error with the exact fix', function (): void {
    putenv('DOLIFLEET_SSH='.DOLICLI_ROOT.'/tests/Fleet/fake-ssh');
    putenv('FAKE_SSH_USER=debian');
    [$app] = consoleApp();
    $app->refresh(['dev1']);
    $app->tick();
    press($app, ['!']);
    expect($app->top())->toBeInstanceOf(BackstageScreen::class)
        ->and(implode("\n", screenOf($app)))->toContain('dolifleet status dev1');

    $error = ErrorScreen::forInstance($app, 'dev1', 'SUDO_FAILED: sudo: a password is required', "sudo: a password is required\n");
    $app->push($error);
    $screen = implode("\n", screenOf($app));
    putenv('DOLIFLEET_SSH');
    putenv('FAKE_SSH_USER');
    $account = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');

    expect($screen)->toContain('Pourquoi :')
        ->and($screen)->toContain("ALL=({$account}) NOPASSWD: ".PHP_BINARY."' > /etc/sudoers.d/dolifleet")
        ->and($screen)->toContain('visudo -c')
        ->and($screen)->toContain('sudo: a password is required')
        ->and($screen)->toContain('c copier la correction');
});

it('runs a read of the palette with completion, on the instance of the screen', function (): void {
    putenv('DOLIFLEET_BODY='.DOLICLI_TEST_DIST.'/dolicli.php');
    [$app, $backend] = consoleApp();
    press($app, [':']);
    expect($app->top())->toBeInstanceOf(PaletteScreen::class);
    typeText($app, 'module li');
    press($app, ['tab']);
    expect(implode("\n", screenOf($app)))->toContain(': module list')
        ->and(screenOf($app)[1])->toBe(' $ dolifleet exec dev1 -- module list');
    press($app, ['enter']);
    putenv('DOLIFLEET_BODY');

    expect($app->top())->toBeInstanceOf(ResultScreen::class)
        ->and($backend->last(['exec', 'dev1', '--', 'module', 'list']))->not->toBeNull();
});

it('welcomes with one key when the inventory is empty, and says when the terminal is too small', function (): void {
    [$app] = consoleApp('en');
    $app->replace(new WelcomeScreen());
    $welcome = implode("\n", screenOf($app));

    expect($welcome)->toContain('dolifleet drives your Dolibarr over SSH')
        ->and($welcome)->toContain('dolifleet add <alias-ssh>')
        ->and(implode("\n", screenOf($app, 70, 20)))->toContain('Terminal too small (70x20)');
});

it('prints the state of the fleet without a terminal, and opens nothing for an unknown instance', function (): void {
    fleetInventory();
    $plain = fleet([]);
    $json = fleet(['console', '--json']);
    $shortcut = fleet(['dev1']);
    $unknown = fleet(['console', 'nope']);

    expect($plain['code'])->toBe(0)
        ->and($plain['out'])->toContain('dev1 PROD')
        ->and($plain['out'])->toContain('dolifleet status all')
        ->and(json_decode($json['out'], true)['instances'])->toHaveKeys(['dev1', 'dev2'])
        ->and($shortcut['code'])->toBe(0)
        ->and($unknown['code'])->toBe(2)
        ->and($unknown['err'])->toContain("Unknown instance 'nope'");
});

it('runs reads as children and writes detached, the journal saying they came from the console', function (): void {
    fleetInventory();
    $dir = DOLICLI_TEST_TMP.'/console-tasks-'.bin2hex(random_bytes(3));
    foreach (['DOLIFLEET_INVENTORY' => DOLICLI_TEST_TMP.'/fleet/inventory.yaml', 'DOLIFLEET_SSH' => DOLICLI_ROOT.'/tests/Fleet/fake-ssh', 'DOLIFLEET_BODY' => DOLICLI_TEST_DIST.'/dolicli.php',
        'XDG_CONFIG_HOME' => DOLICLI_TEST_TMP.'/fleet/config', 'XDG_DATA_HOME' => DOLICLI_TEST_TMP.'/fleet/data'] as $name => $value) {
        putenv($name.'='.$value);
    }
    $backend = new CliBackend([PHP_BINARY, DOLICLI_ROOT.'/fleet/bin/dolifleet'], $dir);
    $read = $backend->start(['status', 'dev2'], 'refresh', ['dev2'], false);
    $write = $backend->start(['maintenance', 'on', 'dev2'], 'maintenance', ['dev2'], true, ['--confirm=yes']);
    $finished = [];
    for ($i = 0; $i < 300 && count($finished) < 2; ++$i) {
        foreach ($backend->poll() as $task) {
            $finished[$task->id] = $task;
        }
        usleep(100000);
    }
    $off = $backend->start(['maintenance', 'off', 'dev2'], 'maintenance', ['dev2'], true, ['--confirm=yes']);
    for ($i = 0; $i < 300 && ! $off->finished(); ++$i) {
        $backend->poll();
        usleep(100000);
    }
    $journal = fleetJournal();
    foreach (['DOLIFLEET_INVENTORY', 'DOLIFLEET_SSH', 'DOLIFLEET_BODY', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME'] as $name) {
        putenv($name);
    }

    expect($finished[$read->id]->json['instances']['dev2']['ok'] ?? null)->toBeTrue()
        ->and($finished[$write->id]->exitCode)->toBe(0)
        ->and($finished[$write->id]->json['instances']['dev2']['envelope']['data']['enabled'] ?? null)->toBeTrue()
        ->and(is_file($dir.'/'.$write->id.'.exit'))->toBeTrue()
        ->and($off->exitCode)->toBe(0)
        ->and(array_column($journal, 'via'))->toContain('console')
        ->and((new CliBackend([PHP_BINARY], $dir))->all())->toHaveCount(2);
});

it('opens the console on a real terminal and gives the terminal back clean on q', function (): void {
    fleetInventory();
    $env = serverEnvironment([
        'DOLIFLEET_INVENTORY' => DOLICLI_TEST_TMP.'/fleet/inventory.yaml',
        'DOLIFLEET_SSH' => DOLICLI_ROOT.'/tests/Fleet/fake-ssh',
        'DOLIFLEET_BODY' => DOLICLI_TEST_DIST.'/dolicli.php',
        'XDG_CONFIG_HOME' => DOLICLI_TEST_TMP.'/fleet/config',
        'XDG_DATA_HOME' => DOLICLI_TEST_TMP.'/fleet/data',
        'TERM' => 'xterm',
        'COLUMNS' => '100',
        'LINES' => '30',
    ]);
    $inner = 'stty cols 100 rows 30; '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(DOLICLI_ROOT.'/fleet/bin/dolifleet').' --lang=en';
    $result = runProcess(['script', '-qec', $inner, '/dev/null'], "q", $env, DOLICLI_ROOT);

    expect($result['out'])->toContain("\e[?1049h")
        ->and($result['out'])->toContain('dolifleet')
        ->and($result['out'])->toContain('dev1')
        ->and($result['out'])->toContain("\e[?1049l");
})->skip( ! is_executable('/usr/bin/script'), 'script (util-linux) is needed for a pseudo-terminal');

it('keeps every column when the flexible one holds a long text, and drops the least useful first when narrow', function (): void {
    $table = (new Dolifleet\Console\View\Table())
        ->column('instance', 'instance', 8, 20, 0)
        ->column('version', 'version', 7, 10, 3)
        ->column('state', 'state', 6, 10, 0)
        ->column('problem', 'to look at', 10, 200, 2, 'left', true)
        ->row(['instance' => 'client-b-prod', 'version' => '20.0.1', 'state' => '? unknown', 'problem' => str_repeat('unreachable: SSH_FAILED connect timed out ', 5)]);
    $wide = Renderer::plain($table->render(100, 5), 100);
    $narrow = Renderer::plain($table->render(40, 5), 40);

    expect($wide[0])->toContain('version')
        ->and($wide[0])->toContain('to look at')
        ->and($wide[1])->toContain('20.0.1')
        ->and(mb_strwidth($wide[1]))->toBeLessThanOrEqual(100)
        ->and($narrow[0])->not->toContain('version')
        ->and($narrow[0])->toContain('state');
});
