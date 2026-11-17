<?php

declare(strict_types=1);

/**
 * Write instances.ini with one instance [id] and its settings.
 *
 * @param  array<string, string>  $settings
 */
function declareBackupInstance(string $id, string $htdocs, array $settings = []): void
{
    $lines = "[{$id}]\nhtdocs = {$htdocs}\n";
    foreach ($settings as $key => $value) {
        $lines .= "{$key} = {$value}\n";
    }
    file_put_contents(serverEnvDirs()['DOLICLI_ETC_DIR'].'/instances.ini', $lines);
}

function backupFixture(string $path, int $mtime, string $content = 'x'): void
{
    if ( ! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $content);
    touch($path, $mtime);
}

it('lists the backups of documents/admin/backup and backup_dir, newest first, like backup.age', function (): void {
    $now = time();
    $instance = isolatedInstance();
    $adminBackup = realpath($instance['documents']).'/admin/backup';
    $external = DOLICLI_TEST_TMP.'/external-backups-'.bin2hex(random_bytes(4));
    backupFixture($adminBackup.'/mysqldump_dolibarr_18.0.10_202611140200.sql.gz', $now - 3 * 86400, str_repeat('d', 2048));
    backupFixture($adminBackup.'/pg_dump_dolibarr_18.0.10_202611070200.sql.bz2', $now - 10 * 86400);
    backupFixture($adminBackup.'/mysqldump_dolibarr_18.0.10_202610280200.sql.err', $now - 20 * 86400);
    mkdir($adminBackup.'/old');
    touch($adminBackup.'/old', $now - 40 * 86400);
    backupFixture($external.'/20261117-020000-database.sql', $now - 3600, 'SQL');
    backupFixture($external.'/20261117-020000-documents.zip', $now - 7200);
    backupFixture($external.'/20261117-020000-modules.zip', $now - 10800);
    backupFixture($external.'/documents_dolibarr_18.0.10_202611150200.tar.gz', $now - 2 * 86400);
    backupFixture($external.'/notes.txt', $now - 30 * 86400);
    backupFixture($external.'/.hidden.sql', $now);
    declareBackupInstance('isotest', $instance['htdocs'], ['backup_dir' => $external]);

    $result = serverJson(['backup', 'list', '--instance=isotest']);
    $data = $result['json']['data'];
    $byFile = array_column($data['backups'], null, 'file');
    $state = json_decode(server(['monitor', 'collect', '--slow', '--stdout', '--instance=isotest'])['out'], true);

    expect($result['code'])->toBe(0)
        ->and(array_column($data['backups'], 'file'))->toBe([
            '20261117-020000-database.sql', '20261117-020000-documents.zip', '20261117-020000-modules.zip',
            'documents_dolibarr_18.0.10_202611150200.tar.gz', 'mysqldump_dolibarr_18.0.10_202611140200.sql.gz',
            'pg_dump_dolibarr_18.0.10_202611070200.sql.bz2', 'mysqldump_dolibarr_18.0.10_202610280200.sql.err', 'notes.txt',
        ])
        ->and(array_column($data['backups'], 'kind'))->toBe(['database', 'documents', 'modules', 'documents', 'database', 'database', 'unknown', 'unknown'])
        ->and($byFile['20261117-020000-database.sql'])->toMatchArray(['path' => $external.'/20261117-020000-database.sql', 'bytes' => 3, 'date' => date('c', $now - 3600)])
        ->and($byFile['20261117-020000-database.sql']['age_seconds'])->toBeGreaterThanOrEqual(3600)->toBeLessThan(3700)
        ->and($byFile['mysqldump_dolibarr_18.0.10_202611140200.sql.gz'])->toMatchArray(['path' => $adminBackup.'/mysqldump_dolibarr_18.0.10_202611140200.sql.gz', 'bytes' => 2048])
        ->and($data['count'])->toBe(8)
        ->and($data['latest'])->toBe(date('c', $now - 3600))
        ->and($data['dirs'])->toBe([$adminBackup, $external])
        ->and($data['unreadable'])->toBe([])
        ->and(json_encode($result['json']))->not->toContain('SQL"')
        ->and($state['checks']['backup.age']['message'])->toContain('20261117-020000-database.sql')
        ->and($state['checks']['backup.age']['value'])->toBeGreaterThanOrEqual(3600)->toBeLessThan(3700);
});

it('applies backup_pattern and renders a table', function (): void {
    $now = time();
    $instance = isolatedInstance();
    $external = DOLICLI_TEST_TMP.'/pattern-backups-'.bin2hex(random_bytes(4));
    backupFixture(realpath($instance['documents']).'/admin/backup/mysqldump_dolibarr_18.0.10_202611140200.sql', $now - 86400);
    backupFixture($external.'/20261117-020000-database.sql.gz', $now - 3600, str_repeat('z', 1536));
    backupFixture($external.'/notes.txt', $now);
    declareBackupInstance('isotest', $instance['htdocs'], ['backup_dir' => $external, 'backup_pattern' => '*.sql.gz']);

    $json = serverJson(['backup', 'list', '--instance=isotest'])['json']['data'];
    $text = server(['backup', 'list', '--instance=isotest', '--format=text']);

    expect(array_column($json['backups'], 'file'))->toBe(['20261117-020000-database.sql.gz'])
        ->and($text['code'])->toBe(0)
        ->and($text['out'])->toContain('20261117-020000-database.sql.gz  database  1.5 KiB')
        ->and($text['out'])->toContain('1.0 h')
        ->and($text['out'])->toContain('Searched: '.realpath($instance['documents']).'/admin/backup, '.$external);
});

it('lists the backups without the database and reports the directories it cannot list', function (): void {
    $base = DOLICLI_TEST_TMP.'/nodb-'.bin2hex(random_bytes(4));
    $htdocs = $base.'/htdocs';
    mkdir($htdocs.'/conf', 0755, true);
    foreach (scandir(DOLICLI_DOLPATH) as $entry) {
        if ( ! in_array($entry, ['.', '..', 'conf'], true)) {
            symlink(DOLICLI_DOLPATH.'/'.$entry, $htdocs.'/'.$entry);
        }
    }
    file_put_contents($htdocs.'/conf/conf.php', "<?php\n\$dolibarr_main_url_root='http://localhost';\n\$dolibarr_main_document_root='{$htdocs}';\n"
        ."\$dolibarr_main_data_root='{$base}/documents';\n\$dolibarr_main_db_host='127.0.0.1';\n\$dolibarr_main_db_port='1';\n"
        ."\$dolibarr_main_db_name='nodb';\n\$dolibarr_main_db_prefix='llx_';\n\$dolibarr_main_db_user='u';\n\$dolibarr_main_db_pass='p';\n\$dolibarr_main_db_type='mysqli';\n");
    backupFixture($base.'/documents/admin/backup/mysqldump_nodb_18.0.10_202611170200.sql', time() - 600);
    $locked = $base.'/locked';
    mkdir($locked, 0755);
    backupFixture($locked.'/hidden.sql', time());
    chmod($locked, 0000);
    declareBackupInstance('nodb', $htdocs, ['backup_dir' => $locked]);

    try {
        $result = serverJson(['backup', 'list', '--instance=nodb']);
    } finally {
        chmod($locked, 0755);
    }

    expect($result['code'])->toBe(0)
        ->and(array_column($result['json']['data']['backups'], 'file'))->toBe(['mysqldump_nodb_18.0.10_202611170200.sql'])
        ->and($result['json']['data']['unreadable'])->toBe([$locked])
        ->and($result['json']['warnings'][0]['code'])->toBe('BACKUP_DIR_UNREADABLE')
        ->and($result['json']['warnings'][0]['message'])->toContain($locked);
});

it('answers an empty list when no backup exists', function (): void {
    $result = serverJson(['backup', 'list']);
    $text = server(['backup', 'list', '--format=text']);
    $dir = realpath(DOLICLI_TEST_DOCUMENTS).'/admin/backup';

    expect($result['code'])->toBe(0)
        ->and($result['json']['data'])->toBe(['backups' => [], 'count' => 0, 'latest' => null, 'dirs' => [$dir], 'unreadable' => []])
        ->and($text['out'])->toBe("No backup found in {$dir}.\n");
});

it('keeps backup as a write command next to the read command backup list', function (): void {
    $manifest = array_column(serverJson(['help'])['json']['data']['commands'], null, 'name');
    $group = serverJson(['help', 'backup', 'list'])['json']['data'];

    expect($manifest['backup'])->toMatchArray(['category' => 'write', 'needs_dolibarr' => true])
        ->and($manifest['backup list'])->toMatchArray(['category' => 'read', 'needs_dolibarr' => false, 'needs_instance' => true, 'params' => []])
        ->and($manifest['cron list'])->toMatchArray(['category' => 'read', 'needs_dolibarr' => true])
        ->and($group['is_command'])->toBeTrue()
        ->and($group['commands'][0]['name'])->toBe('backup list')
        ->and(serverJson(['backup', 'list', '--database'])['json']['errors'][0]['code'])->toBe('UNKNOWN_PARAMETER');
});
