<?php

declare(strict_types=1);

use Dolicli\Upgrade\MigrationPlanner;
use Dolicli\Upgrade\Releases;

/**
 * Isolated instance: an htdocs made of symbolic links to the test Dolibarr, its own conf.php
 * and a copy of documents/. The upgrade commands can rename it without touching the shared one.
 *
 * @return array{root: string, htdocs: string, documents: string}
 */
function isolatedInstance(): array
{
    $root = DOLICLI_TEST_TMP.'/instance-'.bin2hex(random_bytes(4));
    $htdocs = $root.'/htdocs';
    $documents = $root.'/documents';
    mkdir($htdocs.'/conf', 0755, true);
    mkdir($documents, 0755, true);
    foreach (scandir(DOLICLI_DOLPATH) as $entry) {
        if ( ! in_array($entry, ['.', '..', 'conf'], true)) {
            symlink(DOLICLI_DOLPATH.'/'.$entry, $htdocs.'/'.$entry);
        }
    }
    $conf = (string) file_get_contents(DOLICLI_DOLPATH.'/conf/conf.php');
    $conf = str_replace(
        ["dirname(__DIR__) . '/../documents'", "\$dolibarr_main_document_root=dirname(__DIR__);", "dirname(__DIR__) . '/custom'"],
        [var_export($documents, true), '$dolibarr_main_document_root='.var_export($htdocs, true).';', var_export($htdocs.'/custom', true)],
        $conf
    );
    file_put_contents($htdocs.'/conf/conf.php', $conf);
    copy(DOLICLI_TEST_DB, $documents.'/database_dolibarr.sdb');
    file_put_contents($htdocs.'/robots-local.txt', "local file\n");

    return ['root' => $root, 'htdocs' => $htdocs, 'documents' => $documents];
}

/**
 * Small release tree of a given version, and its signature.
 *
 * @return array{tree: string, signature: string}
 */
function fakeRelease(string $version, bool $corrupt = false): array
{
    $tree = DOLICLI_TEST_TMP.'/release-'.bin2hex(random_bytes(4)).'/htdocs';
    mkdir($tree.'/install', 0755, true);
    file_put_contents($tree.'/main.inc.php', "<?php\n// main of {$version}\n");
    file_put_contents($tree.'/filefunc.inc.php', "<?php\nif (!defined('DOL_VERSION')) {\n\tdefine('DOL_VERSION', '{$version}');\n}\n");
    file_put_contents($tree.'/install/upgrade.php', "<?php\n");
    $signature = dirname($tree).'/filelist-'.$version.'.xml';
    file_put_contents($signature, '<?xml version="1.0"?><checksum_list><dolibarr_htdocs_dir>'
        .'<md5file name="/main.inc.php">'.($corrupt ? md5('other') : md5_file($tree.'/main.inc.php')).'</md5file>'
        .'<md5file name="/filefunc.inc.php">'.md5_file($tree.'/filefunc.inc.php').'</md5file>'
        .'</dolibarr_htdocs_dir></checksum_list>');

    return ['tree' => $tree, 'signature' => $signature];
}

it('says the database is up to date when it is', function (): void {
    $result = serverJson(['system', 'upgrade', '--skip-backup']);

    expect($result['code'])->toBe(0)
        ->and($result['json']['data']['steps'])->toBe([])
        ->and($result['json']['data']['message'])->toContain('Database is already at version 18.0.10');
});

it('asks for from= when the database version is unknown', function (): void {
    testDb()->exec("DELETE FROM llx_const WHERE name IN ('MAIN_VERSION_LAST_UPGRADE', 'MAIN_VERSION_LAST_INSTALL')");

    $result = serverJson(['system', 'upgrade', '--skip-backup']);

    expect($result['code'])->toBe(1)
        ->and($result['json']['errors'][0]['code'])->toBe('UNKNOWN_DATABASE_VERSION');
});

it('validates the target version and the source', function (): void {
    expect(serverJson(['system', 'upgrade', '--version=latest'])['code'])->toBe(2)
        ->and(serverJson(['system', 'upgrade', 'source=/tmp'])['json']['errors'][0]['code'])->toBe('MISSING_PARAMETER');
});

it('keeps the maintenance mode on and the install locked after a failed migration', function (): void {
    // Dolibarr cannot migrate a SQLite database: upgrade.php fails, which exercises the error path.
    $result = serverJson(['system', 'upgrade', 'from=17.0.0', '--skip-backup']);

    expect($result['code'])->toBe(1)
        ->and($result['json']['errors'][0]['code'])->toBe('UPGRADE_FAILED')
        ->and($result['json']['errors'][0]['message'])->toContain('upgrade.php failed')
        ->and($result['json']['errors'][0]['details']['recovery'])->toContain('the maintenance mode stays on (dolicli system maintenance disable)')
        ->and($result['err'])->toContain('17.0.0 -> 18.0.0')
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/install.lock'))->toBeTrue()
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/upgrade.unlock'))->toBeFalse()
        ->and(dbValue("SELECT value FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe('admin');
});

it('dumps the database before a database migration', function (): void {
    $dir = DOLICLI_TEST_TMP.'/upbackup-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    serverJson(['system', 'upgrade', 'from=17.0.0', 'backup-dir='.$dir]);

    expect(glob($dir.'/dolibarr_backup_17.0.0_*/database.sql'))->toHaveCount(1);
});

it('describes a full upgrade with --dry-run', function (): void {
    $result = serverJson(['system', 'upgrade', 'version=19.0.0', 'source=/srv/none', '--dry-run']);

    expect($result['code'])->toBe(0)
        ->and(implode("\n", $result['json']['data']['actions']))->toContain('Migrate the database from 18.0.10 to 19.0.0');
});

it('reports the state of an upgrade', function (): void {
    $result = serverJson(['upgrade', 'status']);

    expect($result['code'])->toBe(0)
        ->and($result['json']['data']['files_version'])->toBe('18.0.10')
        ->and($result['json']['data']['database_version'])->toBe('18.0.10')
        ->and($result['json']['data']['upgrade_pending'])->toBeFalse()
        ->and($result['json']['data']['migration_steps'])->toBe([]);
});

it('prepares, switches and restores the files of an instance', function (): void {
    $instance = isolatedInstance();
    $release = fakeRelease('18.0.10');
    $env = ['DOLPATH' => $instance['htdocs']];

    $deploy = serverJson(['upgrade', 'deploy', 'version=18.0.10', 'source='.dirname($release['tree']), 'signature='.$release['signature']], null, $env);
    $staged = $instance['htdocs'].'.new-18.0.10';
    $stagedMain = (string) file_get_contents($staged.'/main.inc.php');
    $stagedConf = (string) file_get_contents($staged.'/conf/conf.php');

    // master.inc.php of the fake release does not exist: the switch is checked on the file tree only.
    $switch = serverJson(['upgrade', 'switch-files', 'version=18.0.10'], null, $env);
    $old = glob($instance['htdocs'].'.old-*');
    $switchedMain = (string) file_get_contents($instance['htdocs'].'/main.inc.php');

    $restore = serverJson(['upgrade', 'restore-files'], null, $env);

    expect($deploy['code'])->toBe(0)
        ->and($deploy['json']['data']['verified'])->toBeTrue()
        ->and($deploy['json']['data']['carried'])->toContain('conf/conf.php')
        ->and($deploy['json']['data']['carried'])->toContain('robots-local.txt')
        ->and($stagedMain)->toContain('main of 18.0.10')
        ->and($stagedConf)->toContain($instance['documents'])
        ->and($switch['code'])->toBe(0)
        ->and($old)->toHaveCount(1)
        ->and($switchedMain)->toContain('main of 18.0.10')
        ->and($restore['code'])->toBe(0)
        ->and(is_link($instance['htdocs'].'/main.inc.php'))->toBeTrue()
        ->and(glob($instance['htdocs'].'.failed-*'))->toHaveCount(1);
});

it('refuses files that do not match the signature and leaves nothing behind', function (): void {
    $instance = isolatedInstance();
    $release = fakeRelease('18.0.10', true);

    $result = serverJson(['upgrade', 'deploy', 'version=18.0.10', 'source='.dirname($release['tree']), 'signature='.$release['signature']], null, ['DOLPATH' => $instance['htdocs']]);

    expect($result['code'])->toBe(1)
        ->and($result['json']['errors'][0]['code'])->toBe('SIGNATURE_MISMATCH')
        ->and($result['json']['errors'][0]['details']['files'])->toBe(['modified /main.inc.php'])
        ->and(file_exists($instance['htdocs'].'.new-18.0.10'))->toBeFalse()
        ->and(glob($instance['root'].'/dolicli-*'))->toBe([]);
});

it('refuses a source of another version', function (): void {
    $instance = isolatedInstance();
    $release = fakeRelease('19.0.2');

    $result = serverJson(['upgrade', 'deploy', 'version=19.0.3', 'source='.$release['tree'], '--skip-verify'], null, ['DOLPATH' => $instance['htdocs']]);

    expect($result['json']['errors'][0]['code'])->toBe('VERSION_MISMATCH');
});

it('holds the plan lease for one holder at a time', function (): void {
    // A lease left by an interrupted run of this test would block it.
    serverJson(['upgrade', 'lease', 'release', 'holder=bob@laptop']);

    $first = serverJson(['upgrade', 'lease', 'take', 'holder=alice@laptop', 'ttl=600']);
    $second = serverJson(['upgrade', 'lease', 'take', 'holder=bob@laptop']);
    $release = serverJson(['upgrade', 'lease', 'release', 'holder=alice@laptop']);
    $third = serverJson(['upgrade', 'lease', 'take', 'holder=bob@laptop']);
    serverJson(['upgrade', 'lease', 'release', 'holder=bob@laptop']);

    expect($first['code'])->toBe(0)
        ->and($second['code'])->toBe(5)
        ->and($second['json']['errors'][0]['code'])->toBe('PLAN_LOCKED')
        ->and($release['code'])->toBe(0)
        ->and($third['code'])->toBe(0);
});

it('stores a plan sent in the stream and shows it in the status', function (): void {
    $plan = json_encode(['from' => '18.0.10', 'to' => '19.0.0', 'steps' => [['type' => 'migration', 'from' => '18.0.0', 'to' => '19.0.0']]]);
    $save = serverEphemeral(['upgrade', 'plan', 'save', 'plan='.$plan, '--format=json']);
    $status = serverJson(['upgrade', 'status']);

    expect($save['code'])->toBe(0)
        ->and($status['json']['data']['plan']['to'])->toBe('19.0.0');
});

it('computes migration steps one major version at a time', function (): void {
    $files = ['16.0.0-17.0.0.sql', '17.0.0-18.0.0.sql', '18.0.0-19.0.0.sql', '19.0.0-20.0.0.sql'];

    expect(MigrationPlanner::plan($files, '17.0.4', '20.0.1'))->toBe([
        ['from' => '17.0.0', 'to' => '18.0.0'],
        ['from' => '18.0.0', 'to' => '19.0.0'],
        ['from' => '19.0.0', 'to' => '20.0.0'],
    ])
        ->and(MigrationPlanner::compareFilesAndDatabase('20.0.1', '20.0.0'))->toBe(1)
        ->and(MigrationPlanner::compareFilesAndDatabase('20.0.1', '20.0.0', true))->toBe(0)
        ->and(MigrationPlanner::compareFilesAndDatabase('19.0.0', '20.0.0'))->toBe(-1);
});

it('reads the published versions', function (): void {
    $html = '<a href="dolibarr-17.0.4.tgz">x</a><a href="dolibarr-18.0.10.zip">y</a><a href="dolibarr-18.0.9.tgz">z</a><a href="dolibarr-22.0.1.tgz">w</a>';
    $versions = Releases::parse($html);

    expect($versions)->toBe(['17.0.4', '18.0.9', '18.0.10', '22.0.1'])
        ->and(Releases::latestMinor('18.0.4', $versions))->toBe('18.0.10')
        ->and(Releases::latest($versions))->toBe('22.0.1');
});
