<?php

declare(strict_types=1);

use Dolifleet\Inventory\Inventory;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Inventory\Targets;
use Dolifleet\Remote\Body;
use Dolifleet\Remote\Dolicli;
use Dolifleet\Remote\Result;
use Dolifleet\Remote\Transport;

beforeEach(function (): void {
    fleetInventory();
});

it('loads the inventory with its includes and resolves the targets', function (): void {
    $shared = DOLICLI_TEST_TMP.'/fleet/shared.yaml';
    file_put_contents($shared, "servers:\n  web-b:\n    instances:\n      client-b:\n        htdocs: /var/www/b/htdocs\n        run_as: www-data\n        tags: [prod, client-b]\n");
    $inventory = Inventory::load(fleetInventory("\ninclude:\n  - shared.yaml\n"));

    expect(array_keys(Targets::resolve($inventory, 'all')))->toBe(['dev1', 'dev2', 'client-b'])
        ->and(array_keys(Targets::resolve($inventory, 'tag:prod')))->toBe(['dev1', 'client-b'])
        ->and(array_keys(Targets::resolve($inventory, 'server:local-a,client-b', 'tag:preprod')))->toBe(['dev1', 'client-b'])
        ->and($inventory->instance('client-b')->runAs)->toBe('www-data')
        ->and($inventory->instance('dev1')->php)->toBe(PHP_BINARY)
        ->and(fn () => Targets::resolve($inventory, 'tag:none'))->toThrow(InventoryException::class, 'selects no instance')
        ->and(fn () => Targets::resolve($inventory, 'nope'))->toThrow(InventoryException::class, "Unknown instance 'nope'");
});

it('treats included values as untrusted and reports the faulty line', function (): void {
    $shared = DOLICLI_TEST_TMP.'/fleet/hostile.yaml';
    file_put_contents($shared, "servers:\n  'evil;rm':\n    instances: {}\n  web-c:\n    instances:\n      all:\n        htdocs: /x\n      dev1:\n        htdocs: /y\n"
        ."      'a,b':\n        htdocs: /z\n      c1:\n        htdocs: '/var/www/$(id)/htdocs'\n        run_as: 'www data'\n");
    $file = fleetInventory("\ninclude:\n  - hostile.yaml\n");

    try {
        Inventory::load($file);
        $this->fail('hostile inventory accepted');
    } catch (InventoryException $e) {
        expect($e->getMessage())->toContain("invalid server alias 'evil;rm'")
            ->and($e->getMessage())->toContain("invalid instance identifier 'all'")
            ->and($e->getMessage())->toContain("instance 'dev1' already defined in")
            ->and($e->getMessage())->toContain("invalid instance identifier 'a,b'")
            ->and($e->getMessage())->toContain('htdocs must be an absolute path without shell characters')
            ->and($e->getMessage())->toContain("invalid run_as 'www data'")
            ->and($e->getMessage())->toMatch('/hostile\.yaml:\d+: /');
    }

    $result = fleet(['config', 'validate']);

    expect($result['code'])->toBe(2);
});

it('builds the remote command with sudo -n and keeps the arguments in the stream', function (): void {
    $inventory = Inventory::load(fleetInventory());
    $transport = new class () implements Transport {
        public function run(string $server, array $command, string $stdin = '', int $timeout = 600): Result
        {
            return new Result(0, '', '');
        }

        public function runMany(array $jobs, int $parallel, int $timeout = 600): array
        {
            return [];
        }

        public function remoteUser(string $server): string
        {
            return 'admin';
        }
    };
    $dolicli = new Dolicli($inventory, $transport, new Body(DOLICLI_TEST_DIST.'/dolicli.php'));
    $instance = $inventory->instance('dev1');
    $job = $dolicli->job($instance, ['user', 'add', 'login=x', 'password=Top-secret-42']);

    expect($job['command'])->toBe(['sudo', '-n', '-u', $instance->runAs, PHP_BINARY, '--'])
        ->and(implode(' ', $job['command']))->not->toContain('Top-secret-42')
        ->and($job['stdin'])->toStartWith('<?php namespace { $DOLICLI_ARGV = ')
        ->and($job['stdin'])->toContain("'password=Top-secret-42'")
        ->and($job['stdin'])->toContain("'--dolpath=".DOLICLI_DOLPATH."'");
});

it('runs a read command on several instances in parallel', function (): void {
    $result = fleetJson(['exec', 'all', '--', 'user', 'list']);

    expect($result['code'])->toBe(0)
        ->and($result['json']['instances']['dev1']['ok'])->toBeTrue()
        ->and($result['json']['instances']['dev2']['envelope']['data']['users'][0]['login'])->toBe('admin');
});

it('asks to type the name of a protected instance, and journals a write with its secret masked', function (): void {
    $refused = fleet(['exec', 'dev1', '--', 'user', 'add', 'login=pilot', 'firstname=P', 'lastname=Ilot']);
    $done = fleetJson(['exec', 'dev1', '--yes', '--', 'user', 'add', 'login=pilot', 'firstname=P', 'lastname=Ilot', 'password=Pilot-pass-123']);
    $journal = fleetJournal();

    expect($refused['code'])->toBe(2)
        ->and($refused['err'])->toContain('Confirmation required')
        ->and($done['code'])->toBe(0)
        ->and(password_verify('Pilot-pass-123', (string) dbValue("SELECT pass_crypted FROM llx_user WHERE login = 'pilot'")))->toBeTrue()
        ->and($journal[0]['phase'])->toBe('intention')
        ->and($journal[0]['arguments'])->toContain('password=***')
        ->and($journal[0]['yes'])->toBeTrue()
        ->and(json_encode($journal))->not->toContain('Pilot-pass-123')
        ->and($journal[1]['phase'])->toBe('result')
        ->and($journal[1]['exit_code'])->toBe(0);
});

it('refuses a destructive command on several instances, and stops a batch at the first failure', function (): void {
    $destructive = fleet(['exec', 'all', '--yes', '--', 'anonymize', 'run']);
    $batch = fleetJson(['exec', 'all', '--yes', '--', 'user', 'edit', 'login=nobody', '--lock']);

    expect($destructive['code'])->toBe(2)
        ->and($destructive['err'])->toContain('not allowed on several instances')
        ->and($batch['code'])->toBe(1)
        ->and(array_keys($batch['json']['instances']))->toBe(['dev1']);
});

it('passes --dry-run to dolicli without confirmation nor journal', function (): void {
    $result = fleetJson(['exec', 'dev1', '--dry-run', '--', 'system', 'maintenance', 'enable']);

    expect($result['code'])->toBe(0)
        ->and($result['json']['instances']['dev1']['envelope']['data']['dry_run'])->toBeTrue()
        ->and(fleetJournal())->toBe([]);
});

it('shows the status from fresh checks, then from the state files', function (): void {
    $first = fleetJson(['status', 'dev1']);
    server(['monitor', 'collect'], null, ['DOLPATH' => DOLICLI_DOLPATH]);
    $second = fleetJson(['status', 'dev1']);
    $table = fleet(['status', 'all']);

    expect($first['code'])->toBe(0)
        ->and($first['json']['instances']['dev1']['source'])->toBe('collected now')
        ->and($first['json']['instances']['dev1']['document']['checks']['database.reachable']['status'])->toBe('ok')
        ->and($second['json']['instances']['dev1']['source'])->toBe('state file')
        ->and($table['out'])->toContain('18.0.10');
});

it('discovers the instances of a server and checks an instance', function (): void {
    $discover = fleetJson(['discover', 'local-a', '--root='.dirname(DOLICLI_DOLPATH, 2), '--php='.PHP_BINARY]);
    $check = fleetJson(['check', 'dev1']);

    expect($discover['code'])->toBe(0)
        ->and($discover['json'][0]['htdocs'])->toBe(DOLICLI_DOLPATH)
        ->and($check['json']['instances']['dev1']['ssh']['ok'])->toBeTrue()
        ->and($check['json']['instances']['dev1']['php']['version'])->toBe(PHP_VERSION)
        ->and($check['json']['instances']['dev1']['dolibarr']['ok'])->toBeTrue()
        ->and($check['json']['instances']['dev1']['installed']['detail'])->toContain('not installed');
});

it('exits with 3 when no instance can be reached', function (): void {
    fleetInventory("  unreachable:\n    instances:\n      far:\n        htdocs: /var/www/far/htdocs\n        run_as: www-data\n");

    $result = fleetJson(['exec', 'far', '--', 'version']);

    expect($result['code'])->toBe(3)
        ->and($result['json']['instances']['far']['error'])->toStartWith('SSH_FAILED');
});

it('switches the maintenance mode on a batch, witness first, with the login of the inventory', function (): void {
    fleetInventory('', '[prod]');
    $inventory = (string) file_get_contents(DOLICLI_TEST_TMP.'/fleet/inventory.yaml');
    file_put_contents(DOLICLI_TEST_TMP.'/fleet/inventory.yaml', str_replace("        tags: [preprod]\n", "        tags: [preprod]\n        maintenance_login: admin\n", $inventory));

    $on = fleetJson(['maintenance', 'on', 'dev2', '--yes']);
    $status = server(['system', 'maintenance', 'status', '--format=json'], null, ['DOLPATH' => DOLICLI_DOLPATH]);
    $off = fleetJson(['maintenance', 'off', 'dev2', '--yes']);

    expect($on['code'])->toBe(0)
        ->and($on['json']['instances']['dev2']['envelope']['data']['allowed_login'])->toBe('admin')
        ->and(json_decode($status['out'], true)['data']['enabled'])->toBeTrue()
        ->and($off['code'])->toBe(0)
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});

it('starts a backup as a detached job and follows it until the end', function (): void {
    $dir = DOLICLI_TEST_TMP.'/fleet-backup';
    @mkdir($dir, 0700, true);

    $started = fleetJson(['backup', 'dev2', '--database', '--dir='.$dir, '--yes']);
    $job = (string) $started['json']['instances']['dev2']['envelope']['data']['id'];
    $followed = fleetJson(['jobs', 'dev2', '--id='.$job, '--wait']);
    $list = fleetJson(['jobs', 'dev2']);

    expect($started['code'])->toBe(0)
        ->and($followed['json']['instances']['dev2']['envelope']['data']['job']['state'])->toBe('done')
        ->and($followed['json']['instances']['dev2']['envelope']['data']['job']['result']['ok'])->toBeTrue()
        ->and(glob($dir.'/*-database.sql'))->toHaveCount(1)
        ->and(array_column($list['json']['instances']['dev2']['envelope']['data']['jobs'], 'id'))->toContain($job);
});

it('installs dolicli on the servers with the instances.ini of the inventory', function (): void {
    $result = fleet(['setup', 'dev1', '--yes', '--json'], null, ['FAKE_SSH_USER' => 'root']);
    $json = json_decode($result['out'], true);
    $dirs = serverEnvDirs();
    $ini = (string) file_get_contents($dirs['DOLICLI_ETC_DIR'].'/instances.ini');

    expect($result['code'])->toBe(0)
        ->and($json['servers']['local-a']['ok'])->toBeTrue()
        ->and(file_get_contents($dirs['DOLICLI_PREFIX'].'/lib/dolicli/dolicli.php'))->toBe(file_get_contents(DOLICLI_TEST_DIST.'/dolicli.php'))
        ->and($ini)->toContain("[dev1]\nhtdocs = \"".DOLICLI_DOLPATH.'"')
        ->and($ini)->toContain('[dev2]')
        ->and((string) file_get_contents($dirs['DOLICLI_CRON_FILE']))->toContain('monitor collect --instance=dev1');
});

it('adds a server and the instances found on it to a new inventory', function (): void {
    $file = DOLICLI_TEST_TMP.'/fleet/new/inventory.yaml';

    $result = fleet(['add', 'local-b', '--root='.dirname(DOLICLI_DOLPATH, 2), '--php='.PHP_BINARY, '--instances=dolibarr-integration-sqlite', '--tags=preprod,client-x'], null, ['DOLIFLEET_INVENTORY' => $file]);
    $inventory = Inventory::load($file);

    expect($result['code'])->toBe(0)
        ->and($inventory->instance('dolibarr-integration-sqlite')->htdocs)->toBe(DOLICLI_DOLPATH)
        ->and($inventory->instance('dolibarr-integration-sqlite')->tags)->toBe(['preprod', 'client-x'])
        ->and($inventory->servers())->toHaveKey('local-b')
        ->and(fleet(['add', 'local-b'], null, ['DOLIFLEET_INVENTORY' => $file])['code'])->toBe(2);
});

it('computes an upgrade plan from the state of the instance', function (): void {
    $result = fleetJson(['upgrade', 'plan', 'dev2', '--to=19.0.0']);
    $plan = $result['json'];

    expect($result['code'])->toBe(0)
        ->and($plan['from'])->toBe('18.0.10')
        ->and($plan['blockers'])->toBe([])
        ->and(array_column($plan['steps'], 'id'))->toBe(['lease', 'maintenance', 'cron', 'modules-off', 'backup', 'archive', 'deploy', 'switch-files', 'migration', 'finalize', 'verify', 'reopen'])
        ->and($plan['steps'][8]['migrations'])->toBe([['from' => '18.0.0', 'to' => '19.0.0']])
        ->and($plan['php_cli'])->not->toBe('')
        ->and(implode(' ', $plan['warnings']))->toContain('web_php is not declared')
        ->and(is_file(DOLICLI_TEST_TMP.'/fleet/data/dolifleet/plans/dev2.json'))->toBeTrue()
        ->and(fleet(['upgrade', 'plan', 'dev2', '--to=18.0.9'])['code'])->toBe(1);
});

it('runs an upgrade plan step by step and stops with the maintenance on when a step fails', function (): void {
    $instance = isolatedInstance();
    $release = fakeRelease('18.0.11');
    $archive = dirname($release['tree']).'/dolibarr-18.0.11.tgz';
    exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg(dirname($release['tree'])).' htdocs');
    $account = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
    fleetInventory("  local-iso:\n    php: ".PHP_BINARY."\n    instances:\n      iso:\n        htdocs: {$instance['htdocs']}\n        run_as: {$account}\n        maintenance_login: admin\n");

    fleetJson(['upgrade', 'plan', 'iso', '--to=18.0.11']);
    $run = fleetJson(['upgrade', 'run', 'iso', '--yes', '--archive='.$archive, '--signature='.$release['signature']]);
    $progress = $run['json']['plan']['progress'];
    $isoDb = new PDO('sqlite:'.$instance['documents'].'/database_dolibarr.sdb');

    expect($run['code'])->toBe(1)
        ->and($run['json']['state'])->toBe('failed')
        ->and($progress['lease']['state'])->toBe('done')
        ->and($progress['backup']['state'])->toBe('done')
        ->and($progress['deploy']['state'])->toBe('done')
        ->and($progress['switch-files']['state'])->toBe('done')
        ->and($progress['migration']['state'])->toBe('failed')
        ->and(file_get_contents($instance['htdocs'].'/main.inc.php'))->toContain('main of 18.0.11')
        ->and(glob($instance['htdocs'].'.old-*'))->toHaveCount(1)
        ->and(glob($instance['root'].'/*-database.sql'))->toHaveCount(1)
        ->and($isoDb->query("SELECT value FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'")->fetchColumn())->toBe('admin')
        ->and(implode("\n", $run['json']['plan']['recovery']))->toContain('upgrade restore-files');

    $restore = fleetJson(['exec', 'iso', '--yes', '--', 'upgrade', 'restore-files']);

    expect($restore['code'])->toBe(0)
        ->and(is_link($instance['htdocs'].'/main.inc.php'))->toBeTrue();
});

it('accepts the confirmation answer of a front end, checks it and journals the front end', function (): void {
    $wrong = fleet(['exec', 'dev1', '--json', '--confirm=dev2', '--', 'system', 'maintenance', 'enable']);
    $right = fleet(['exec', 'dev1', '--json', '--confirm=dev1', '--', 'system', 'maintenance', 'enable'], null, ['DOLIFLEET_FRONTEND' => 'console']);
    fleet(['maintenance', 'off', 'dev1', '--confirm=dev1']);
    $journal = fleetJournal();

    expect($wrong['code'])->toBe(2)
        ->and($wrong['err'])->toContain('Not confirmed')
        ->and($right['code'])->toBe(0)
        ->and(json_decode($right['out'], true)['instances']['dev1']['envelope']['data']['enabled'])->toBeTrue()
        ->and($journal[0]['via'])->toBe('console')
        ->and($journal[0]['yes'])->toBeFalse()
        ->and($journal[2]['via'])->toBe('cli')
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});

it('describes a backup with --dry-run without starting a job nor journaling', function (): void {
    $result = fleetJson(['backup', 'dev2', '--database', '--documents', '--dry-run']);
    $data = $result['json']['instances']['dev2']['envelope']['data'];

    expect($result['code'])->toBe(0)
        ->and($data['dry_run'])->toBeTrue()
        ->and(implode(' ', $data['actions']))->toContain('-database.sql')
        ->and(fleetJournal())->toBe([]);
});

it('pauses an upgrade at a manual step without a terminal, and resumes it once the step is declared done', function (): void {
    $instance = isolatedInstance();
    $release = fakeRelease('18.0.11');
    $archive = dirname($release['tree']).'/dolibarr-18.0.11.tgz';
    exec('tar -czf '.escapeshellarg($archive).' -C '.escapeshellarg(dirname($release['tree'])).' htdocs');
    $account = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
    fleetInventory("  local-iso:\n    php: ".PHP_BINARY."\n    instances:\n      iso:\n        htdocs: {$instance['htdocs']}\n        run_as: {$account}\n        maintenance_login: admin\n");
    $sources = ['--archive='.$archive, '--signature='.$release['signature']];

    $plan = fleetJson(['upgrade', 'plan', 'iso', '--to=18.0.11']);
    $manual = array_values(array_filter($plan['json']['steps'], fn (array $step): bool => 'manual' === $step['type']))[0]['id'];
    $refused = fleet(array_merge(['upgrade', 'run', 'iso', '--json', '--confirm=other'], $sources));
    $paused = fleetJson(array_merge(['upgrade', 'run', 'iso', '--confirm=iso'], $sources));
    $resumed = fleetJson(array_merge(['upgrade', 'resume', 'iso', '--confirm=iso', '--done='.$manual], $sources));

    expect($refused['code'])->toBe(2)
        ->and($paused['code'])->toBe(0)
        ->and($paused['json']['state'])->toBe('paused')
        ->and($paused['json']['plan']['progress'][$manual]['state'])->toBe('paused')
        ->and($resumed['json']['plan']['progress'][$manual]['state'])->toBe('done')
        ->and($resumed['json']['plan']['progress']['backup']['state'])->toBe('done');
});
