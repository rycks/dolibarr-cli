<?php

declare(strict_types=1);

use Dolicli\Monitor\Formats;
use Dolicli\Monitor\State;
use Dolicli\Monitor\Thresholds;
use Dolicli\Php\Matrix;

function currentAccount(): string
{
    return (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
}

function testStateFile(string $id = 'dolibarr-integration-sqlite'): string
{
    return serverEnvDirs()['DOLICLI_STATE_DIR'].'/'.currentAccount().'/'.$id.'.json';
}

/**
 * @param  array<string, string>  $settings
 */
function declareTestInstance(array $settings = []): void
{
    $lines = "[testinst]\nhtdocs = ".DOLICLI_DOLPATH."\nrun_as = ".currentAccount()."\nphp = ".PHP_BINARY."\n";
    foreach ($settings as $key => $value) {
        $lines .= "{$key} = {$value}\n";
    }
    file_put_contents(serverEnvDirs()['DOLICLI_ETC_DIR'].'/instances.ini', $lines);
}

it('writes the fast checks in a private state file that matches the schema', function (): void {
    $result = serverJson(['monitor', 'collect']);
    $file = testStateFile();
    $state = json_decode((string) file_get_contents($file), true);

    expect($result['code'])->toBe(0)
        ->and(fileperms($file) & 0777)->toBe(0640)
        ->and($state['checks']['database.reachable']['status'])->toBe('ok')
        ->and($state['checks']['dolibarr.version']['value'])->toBe('18.0.10')
        ->and($state['checks']['dolibarr.upgrade_pending']['value'])->toBeFalse()
        ->and($state['checks']['php.web_version']['status'])->toBe('skipped')
        ->and($state['checks']['security.login_failures_24h']['status'])->toBe('skipped')
        ->and($state['checks'])->not->toHaveKey('backup.age');
    assertMatchesSchema((string) file_get_contents($file), 'state');
});

it('merges the slow checks with the fast ones', function (): void {
    serverJson(['monitor', 'collect']);
    serverJson(['monitor', 'collect', '--slow']);
    $state = json_decode((string) file_get_contents(testStateFile()), true);

    expect($state['checks'])->toHaveKeys(['database.reachable', 'backup.age', 'storage.database_bytes', 'filecheck.modified'])
        ->and($state['checks']['backup.age']['status'])->toBe('critical')
        ->and($state['checks']['storage.database_bytes']['value'])->toBe(filesize(DOLICLI_TEST_DB))
        ->and($state['checks']['filecheck.modified']['status'])->toBe('unknown')
        ->and($state['checks']['tls.expiry_seconds']['status'])->toBe('skipped');
    assertMatchesSchema((string) file_get_contents(testStateFile()), 'state');
});

it('measures the age of the most recent backup against the thresholds', function (): void {
    $dir = DOLICLI_TEST_TMP.'/backups-'.bin2hex(random_bytes(4));
    mkdir($dir);
    touch($dir.'/old.sql.gz', time() - 200000);
    touch($dir.'/notes.txt', time());
    declareTestInstance(['backup_dir' => $dir, 'backup_pattern' => '*.sql.gz']);

    serverJson(['monitor', 'collect', '--slow', '--instance=testinst']);
    $state = json_decode((string) file_get_contents(testStateFile('testinst')), true);

    expect($state['checks']['backup.age']['status'])->toBe('warning')
        ->and($state['checks']['backup.age']['value'])->toBeGreaterThanOrEqual(200000)
        ->and($state['checks']['backup.age']['thresholds'])->toBe(['warning' => 129600, 'critical' => 259200]);
});

it('writes the state on stdout without touching the file', function (): void {
    $result = server(['monitor', 'collect', '--stdout']);

    expect($result['code'])->toBe(0)
        ->and(json_decode($result['out'], true)['checks'])->toHaveKey('dolicli.operation')
        ->and(file_exists(testStateFile()))->toBeFalse();
    assertMatchesSchema($result['out'], 'state');
});

it('shows the state with freshness applied and the collector age computed', function (): void {
    serverJson(['monitor', 'collect']);
    $file = testStateFile();
    $state = json_decode((string) file_get_contents($file), true);
    $state['checks']['sessions.count']['collected_at'] = date('c', time() - 3600);
    file_put_contents($file, json_encode($state));

    $result = server(['monitor', 'show', '--format=json']);
    $shown = json_decode($result['out'], true);

    expect($result['code'])->toBe(0)
        ->and($shown['checks']['collector.age']['status'])->toBe('ok')
        ->and($shown['checks']['sessions.count']['status'])->toBe('unknown')
        ->and($shown['checks']['sessions.count']['message'])->toStartWith('Stale')
        ->and($shown['status'])->not->toBe('ok');
    assertMatchesSchema($result['out'], 'state');
});

it('exports the state for Prometheus, in a file written atomically', function (): void {
    serverJson(['monitor', 'collect']);
    $prom = DOLICLI_TEST_TMP.'/textfile/dolibarr.prom';
    @mkdir(dirname($prom));

    $stdout = server(['monitor', 'show', '--format=prometheus']);
    $written = server(['monitor', 'show', '--format=prometheus', '--output='.$prom]);

    expect($stdout['code'])->toBe(0)
        ->and($stdout['out'])->toContain('dolibarr_info{dolibarr_instance="dolibarr-integration-sqlite",version="18.0.10"')
        ->and($stdout['out'])->toContain('dolibarr_check_status{dolibarr_instance="dolibarr-integration-sqlite",check="database.reachable"} 0')
        ->and($stdout['out'])->not->toContain('check="php.web_version"')
        ->and($stdout['out'])->toMatch('/^dolibarr_collect_timestamp_seconds\{dolibarr_instance="dolibarr-integration-sqlite"\} \d+$/m')
        ->and($written['out'])->toBe('')
        ->and(file_get_contents($prom))->toContain('# TYPE dolibarr_info gauge')
        ->and(fileperms($prom) & 0777)->toBe(0644);
});

it('answers Nagios with its exit codes, UNKNOWN for any error of dolicli', function (): void {
    serverJson(['monitor', 'collect', '--slow']);
    serverJson(['monitor', 'collect']);

    $all = server(['monitor', 'show', '--format=nagios']);
    $one = server(['monitor', 'show', '--format=nagios', '--check=database.reachable']);
    $missing = server(['monitor', 'show', '--format=nagios', 'file=/nonexistent.json']);
    $typo = server(['monitor', 'show', '--format=nagios', '--chek=x']);

    expect($all['code'])->toBe(2)
        ->and($all['out'])->toStartWith('DOLIBARR CRITICAL - dolibarr-integration-sqlite: ')
        ->and($all['out'])->toContain('| ')
        ->and($all['out'])->toContain('database_reachable=1;;')
        ->and($one['code'])->toBe(0)
        ->and($one['out'])->toStartWith('DOLIBARR OK - dolibarr-integration-sqlite: all checks ok')
        ->and($missing['code'])->toBe(3)
        ->and($missing['out'])->toStartWith('DOLIBARR UNKNOWN')
        ->and($typo['code'])->toBe(3);
});

it('still writes the state when the database is unreachable', function (): void {
    $htdocs = DOLICLI_TEST_TMP.'/downdb/htdocs';
    mkdir($htdocs.'/conf', 0755, true);
    mkdir(DOLICLI_TEST_TMP.'/downdb/documents', 0755, true);
    foreach (scandir(DOLICLI_DOLPATH) as $entry) {
        if ( ! in_array($entry, ['.', '..', 'conf'], true)) {
            symlink(DOLICLI_DOLPATH.'/'.$entry, $htdocs.'/'.$entry);
        }
    }
    file_put_contents($htdocs.'/conf/conf.php', "<?php\n\$dolibarr_main_url_root='https://erp.example';\n\$dolibarr_main_document_root='{$htdocs}';\n"
        ."\$dolibarr_main_data_root='".DOLICLI_TEST_TMP."/downdb/documents';\n\$dolibarr_main_db_host='127.0.0.1';\n\$dolibarr_main_db_port='1';\n"
        ."\$dolibarr_main_db_name='nodb';\n\$dolibarr_main_db_prefix='llx_';\n\$dolibarr_main_db_user='u';\n\$dolibarr_main_db_pass='p';\n\$dolibarr_main_db_type='mysqli';\n");

    $result = serverJson(['monitor', 'collect'], null, ['DOLPATH' => $htdocs]);
    $state = json_decode((string) file_get_contents(testStateFile('downdb')), true);

    expect($result['code'])->toBe(3)
        ->and($state['status'])->toBe('critical')
        ->and($state['instance']['url'])->toBe('https://erp.example')
        ->and($state['checks']['database.reachable']['status'])->toBe('critical')
        ->and($state['checks']['dolibarr.db_version']['status'])->toBe('unknown')
        ->and($state['checks']['dolibarr.version']['value'])->toBe('18.0.10')
        ->and($state['checks']['storage.free_bytes']['status'])->not->toBe('unknown');
});

it('does not query the database during an operation and reports it', function (): void {
    $lock = Dolicli\System\Lock::forDataRoot(DOLICLI_TEST_DOCUMENTS);
    $lock->acquire('system upgrade');
    try {
        serverJson(['monitor', 'collect']);
    } finally {
        $lock->release();
    }
    $state = json_decode((string) file_get_contents(testStateFile()), true);

    expect($state['checks']['dolicli.operation']['value']['command'])->toBe('system upgrade')
        ->and($state['checks']['cron.failed']['status'])->toBe('unknown')
        ->and($state['checks']['cron.failed']['message'])->toContain('operation is running');
});

it('feeds the low-level discovery of Zabbix', function (): void {
    declareTestInstance();
    serverJson(['monitor', 'collect', '--instance=testinst']);

    $result = server(['monitor', 'discover']);
    $data = json_decode($result['out'], true);

    expect($result['code'])->toBe(0)
        ->and($data['data'][0]['{#INSTANCE}'])->toBe('testinst')
        ->and($data['data'][0]['{#URL}'])->toBe('http://localhost')
        ->and($data['data'][0]['{#STATEFILE}'])->toBe(testStateFile('testinst'));
});

it('gives an immediate status and counts the sessions of a readable session directory', function (): void {
    $sessions = DOLICLI_TEST_TMP.'/sessions-'.bin2hex(random_bytes(4));
    mkdir($sessions);
    $company = (string) (dbValue("SELECT value FROM llx_const WHERE name = 'MAIN_INFO_SOCIETE_NOM'") ?: '');
    file_put_contents($sessions.'/sess_abc', 'dol_login|s:5:"admin";dol_entity|i:1;dol_company|s:'.mb_strlen($company).':"'.$company.'";newtoken|s:3:"tok";');
    $env = serverEnvironment([]);

    $status = serverJson(['system', 'status']);
    $count = runProcess([PHP_BINARY, '-d', 'session.save_path='.$sessions, DOLICLI_TEST_DIST.'/dolicli', 'system', 'sessions', '--details', '--format=json'], null, $env, DOLICLI_ROOT);
    $json = json_decode($count['out'], true);

    expect($status['code'])->toBe(0)
        ->and($status['json']['data']['checks'])->toHaveKey('database.reachable')
        ->and($json['data']['count'])->toBe(1)
        ->and($json['data']['sessions'][0]['login'])->toBe('admin')
        ->and($count['out'])->not->toContain('tok');
});

it('installs the code, the state directories and the cron of the declared instances', function (): void {
    $dirs = serverEnvDirs();
    $prom = DOLICLI_TEST_TMP.'/prom-'.bin2hex(random_bytes(4));
    declareTestInstance(['prom_dir' => $prom]);

    $result = serverJson(['setup', 'install']);
    $cron = (string) file_get_contents($dirs['DOLICLI_CRON_FILE']);

    expect($result['code'])->toBe(0)
        ->and(file_get_contents($dirs['DOLICLI_PREFIX'].'/lib/dolicli/dolicli.php'))->toBe(file_get_contents(DOLICLI_TEST_DIST.'/dolicli.php'))
        ->and(fileperms($dirs['DOLICLI_PREFIX'].'/bin/dolicli') & 0777)->toBe(0755)
        ->and(fileperms($dirs['DOLICLI_STATE_DIR'].'/'.currentAccount()) & 07777)->toBe(02750)
        ->and(is_dir($prom))->toBeTrue()
        ->and($cron)->toStartWith("# Written by dolicli setup install")
        ->and($cron)->toContain("MAILTO=\"\"\n")
        ->and($cron)->toContain('*/5 * * * *  '.currentAccount().'  '.PHP_BINARY.' '.$dirs['DOLICLI_PREFIX'].'/bin/dolicli monitor collect --instance=testinst --quiet')
        ->and($cron)->toContain('--slow --quiet')
        ->and($cron)->toContain('--output='.$prom.'/dolibarr-testinst.prom')
        ->and(mb_substr($cron, -1))->toBe("\n");

    $installed = runProcess([PHP_BINARY, $dirs['DOLICLI_PREFIX'].'/bin/dolicli', 'version', '--format=json'], null, serverEnvironment([]), DOLICLI_ROOT);

    expect(json_decode($installed['out'], true)['data']['dolicli_version'])->toBe('test');
});

it('refuses to replace a newer installed version without --force', function (): void {
    $dist = DOLICLI_TEST_TMP.'/dist-new';
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(DOLICLI_ROOT.'/build/build-server.php').' --output='.escapeshellarg($dist).' --version=2.1.0');
    $older = DOLICLI_TEST_TMP.'/dist-old';
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(DOLICLI_ROOT.'/build/build-server.php').' --output='.escapeshellarg($older).' --version=2.0.3');

    $first = serverJson(['setup', 'install', 'body='.$dist.'/dolicli.php', 'launcher='.$dist.'/dolicli']);
    $downgrade = serverJson(['setup', 'install', 'body='.$older.'/dolicli.php', 'launcher='.$older.'/dolicli']);
    $forced = serverJson(['setup', 'install', 'body='.$older.'/dolicli.php', 'launcher='.$older.'/dolicli', '--force']);
    $dev = serverJson(['setup', 'install', 'body='.DOLICLI_ROOT.'/server/dev.php', 'launcher='.DOLICLI_ROOT.'/server/launcher.php']);

    expect($first['code'])->toBe(0)
        ->and($downgrade['code'])->toBe(1)
        ->and($downgrade['json']['errors'][0]['code'])->toBe('NEWER_INSTALLED')
        ->and($forced['code'])->toBe(0)
        ->and($dev['json']['errors'][0]['code'])->toBe('BODY_NOT_BUILT');
});

it('evaluates thresholds in both directions with overrides per instance', function (): void {
    $file = DOLICLI_TEST_TMP.'/thresholds.ini';
    file_put_contents($file, "[default]\nbackup.age.warning = 1000\ntls.enabled = 1\n\n[prod]\nbackup.age.critical = 2000\n");
    $prod = Thresholds::forInstance('prod', $file);
    $other = Thresholds::forInstance('other', $file);

    expect($prod->evaluate('backup.age', 1500)[0])->toBe('warning')
        ->and($prod->evaluate('backup.age', 2500)[0])->toBe('critical')
        ->and($other->evaluate('backup.age', 2500)[0])->toBe('warning')
        ->and($other->evaluate('backup.age', 2500)[1])->toBe(['warning' => 1000, 'critical' => 259200])
        ->and($prod->evaluate('storage.free_bytes', 500000000)[0])->toBe('critical')
        ->and($prod->evaluate('storage.free_bytes', 3000000000)[0])->toBe('warning')
        ->and($prod->evaluate('sessions.count', 99)[0])->toBe('ok')
        ->and($prod->option('tls.enabled'))->toBe('1');
});

it('orders statuses and ignores skipped checks', function (): void {
    expect(State::overall(['a' => ['status' => 'ok'], 'b' => ['status' => 'skipped']]))->toBe('ok')
        ->and(State::overall(['a' => ['status' => 'warning'], 'b' => ['status' => 'unknown']]))->toBe('unknown')
        ->and(State::overall(['a' => ['status' => 'unknown'], 'b' => ['status' => 'critical']]))->toBe('critical');
});

it('escapes Prometheus labels', function (): void {
    $out = Formats::prometheus(['instance' => ['id' => 'a"b'], 'status' => 'ok', 'generated_at_unix' => 1, 'checks' => [
        'dolibarr.version' => ['status' => 'ok', 'value' => "1\n2"],
    ]]);

    expect($out)->toContain('dolibarr_instance="a\"b",version="1\n2"');
});

it('knows the PHP range of every Dolibarr version', function (): void {
    expect(Matrix::check('12.0.5', '7.4.33'))->toBe(Matrix::OK)
        ->and(Matrix::check('12.0.5', '8.2.1'))->toBe(Matrix::INCOMPATIBLE)
        ->and(Matrix::check('18.0.2', '7.0.33'))->toBe(Matrix::WARNING)
        ->and(Matrix::check('18.0.3', '5.6.40'))->toBe(Matrix::INCOMPATIBLE)
        ->and(Matrix::check('20.0.0', '8.2.12'))->toBe(Matrix::OK)
        ->and(Matrix::check('23.0.0', '7.0.33'))->toBe(Matrix::INCOMPATIBLE)
        ->and(Matrix::check('6.0.8', '7.0.33'))->toBe(Matrix::UNKNOWN)
        ->and(Matrix::range('12.0.0')['max_source'])->toContain('ChangeLog 11.0.0');
});

it('ships a Zabbix template that covers every check', function (): void {
    $yaml = (string) file_get_contents(DOLICLI_ROOT.'/contrib/zabbix/dolicli_template.yaml');

    foreach (array_merge(Dolicli\Monitor\Collector::fastChecks(), Dolicli\Monitor\Collector::slowChecks()) as $check) {
        expect($yaml)->toContain("dolicli.check.status[{#INSTANCE},{$check}]");
    }
    expect($yaml)->toContain("key: 'vfs.file.contents[{#STATEFILE}]'")
        ->and($yaml)->toContain('key: dolicli.discover');
});
