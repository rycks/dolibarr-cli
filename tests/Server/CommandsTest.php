<?php

declare(strict_types=1);

/**
 * @param  array<int, string>  $args
 * @param  array<string, string|false>  $env
 * @return array{code: int, out: string, err: string}
 */
function serverText(array $args, ?string $stdin = null, array $env = [], string $php = PHP_BINARY): array
{
    return server(array_merge($args, ['--format=text']), $stdin, $env, $php);
}

function constValue(string $name, int $entity = 1): mixed
{
    return dbValue('SELECT value FROM llx_const WHERE name = ? AND entity IN (0, ?)', [$name, $entity]);
}

dataset('dolibarr php', fn (): array => phpMatrix(true));

it('lists, creates, edits and shows users under every PHP able to load Dolibarr', function (string $php): void {
    $add = serverJson(['user', 'add', 'login=jdoe', 'firstname=John', 'lastname=Doe', 'email=j@example.com', '--password-stdin'], "S3cure-pass-42\n", [], $php);
    $lock = serverJson(['user', 'edit', 'login=jdoe', '--lock'], null, [], $php);
    $list = serverJson(['user', 'list'], null, [], $php);
    $show = serverJson(['user', 'show', 'login=jdoe'], null, [], $php);
    $users = array_column($list['json']['data']['users'], null, 'login');

    expect($add['code'])->toBe(0)
        ->and($add['json']['acting_user'])->toBe('admin')
        ->and(array_column($add['json']['warnings'], 'code'))->toContain('ACTING_AS_FIRST_ADMIN')
        ->and(password_verify('S3cure-pass-42', (string) dbValue("SELECT pass_crypted FROM llx_user WHERE login = 'jdoe'")))->toBeTrue()
        ->and($lock['code'])->toBe(0)
        ->and($users['jdoe']['status'])->toBe('disabled')
        ->and($users['admin']['admin'])->toBe('superadmin')
        ->and($show['json']['data']['email'])->toBe('j@example.com')
        ->and($show['json']['data']['api_key'])->toBe('not set');
})->with('dolibarr php');

it('refuses secrets on the command line and accepts them in the stream', function (): void {
    $refused = serverJson(['user', 'add', 'login=x', 'firstname=a', 'lastname=b', 'password=Pass-word-123']);
    $stream = serverEphemeral(['user', 'add', 'login=streamed', 'firstname=a', 'lastname=b', 'password=Pass-word-123', '--format=json']);

    expect($refused['code'])->toBe(2)
        ->and($refused['json']['errors'][0]['code'])->toBe('SECRET_ON_COMMAND_LINE')
        ->and($stream['code'])->toBe(0)
        ->and(password_verify('Pass-word-123', (string) dbValue("SELECT pass_crypted FROM llx_user WHERE login = 'streamed'")))->toBeTrue();
});

it('does not leave a user behind when the password is rejected', function (): void {
    testDb()->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('USER_PASSWORD_GENERATED', 1, 'Perso', 'chaine', 0)");
    testDb()->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('USER_PASSWORD_PATTERN', 1, '12;1;1;1;3;1', 'chaine', 0)");

    $result = serverJson(['user', 'add', 'login=weak', 'firstname=W', 'lastname=K', '--password-stdin'], "abc\n");

    expect($result['code'])->toBe(1)
        ->and($result['json']['errors'][0]['code'])->toBe('PASSWORD_REJECTED')
        ->and(dbValue("SELECT COUNT(*) FROM llx_user WHERE login = 'weak'"))->toBe(0);
});

it('changes a password without printing it, and generates an API key once', function (): void {
    $password = serverText(['user', 'password', 'login=admin', '--password-stdin', '--force'], "N3w-admin-pass\n");
    $first = serverJson(['user', 'token', 'login=admin']);
    $second = serverJson(['user', 'token', 'login=admin']);

    expect($password['code'])->toBe(0)
        ->and($password['out'].$password['err'])->not->toContain('N3w-admin-pass')
        ->and(password_verify('N3w-admin-pass', (string) dbValue("SELECT pass_crypted FROM llx_user WHERE login = 'admin'")))->toBeTrue()
        ->and($first['json']['data']['api_key'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($second['code'])->toBe(1)
        ->and($second['json']['errors'][0]['code'])->toBe('API_KEY_EXISTS')
        ->and(json_encode($second['json']))->not->toContain($first['json']['data']['api_key']);
});

it('acts as the user given by --as and records it in the journal', function (): void {
    serverJson(['user', 'add', 'login=tech', 'firstname=T', 'lastname=Tech', 'admin=1', '--password-stdin'], "Tech-pass-1234\n");
    $result = serverJson(['system', 'maintenance', 'enable', '--as=tech']);
    $journal = (string) file_get_contents(DOLICLI_TEST_DOCUMENTS.'/dolicli/journal.log');

    expect($result['code'])->toBe(0)
        ->and($journal)->toContain('"command":"user add"')
        ->and($journal)->toContain('"command":"system maintenance enable"')
        ->and($journal)->not->toContain('Tech-pass-1234');
});

it('describes a write with --dry-run without doing it', function (): void {
    $journal = DOLICLI_TEST_DOCUMENTS.'/dolicli/journal.log';
    $before = is_file($journal) ? (string) file_get_contents($journal) : '';

    $result = serverJson(['user', 'add', 'login=dry', 'firstname=D', 'lastname=R', '--dry-run']);
    $text = serverText(['system', 'maintenance', 'enable', '--dry-run']);

    expect($result['code'])->toBe(0)
        ->and($result['json']['data']['dry_run'])->toBeTrue()
        ->and(dbValue("SELECT COUNT(*) FROM llx_user WHERE login = 'dry'"))->toBe(0)
        ->and($text['out'])->toContain('Dry run, nothing written')
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0)
        ->and(is_file($journal) ? (string) file_get_contents($journal) : '')->toBe($before);
});

it('lists, activates and deactivates modules', function (): void {
    $list = serverJson(['module', 'list', '--active']);
    $on = serverText(['module', 'activate', 'name=Projet']);
    $again = serverJson(['module', 'activate', 'name=modProjet']);
    $off = serverText(['module', 'deactivate', 'name=modProjet']);
    $unknown = serverJson(['module', 'activate', 'name=modNoSuchThing']);

    expect(array_column($list['json']['data']['modules'], 'class'))->toContain('modUser')
        ->and($on['out'])->toContain("Module 'modProjet' activated.")
        ->and($again['json']['data']['changed'])->toBeFalse()
        ->and($off['out'])->toContain("Module 'modProjet' deactivated.")
        ->and(constValue('MAIN_MODULE_PROJET'))->toBeFalse()
        ->and($unknown['code'])->toBe(1)
        ->and($unknown['json']['errors'][0]['code'])->toBe('MODULE_NOT_FOUND');
});

it('asks before deactivating every module and exits 4 without a terminal', function (): void {
    $refused = serverJson(['module', 'deactivate', '--all']);
    $done = serverJson(['module', 'deactivate', '--all', '--yes']);

    expect($refused['code'])->toBe(4)
        ->and($refused['json']['errors'][0]['code'])->toBe('CONFIRMATION_REQUIRED')
        ->and($done['code'])->toBe(0)
        ->and(constValue('MAIN_MODULE_EXPORT'))->toBeFalse()
        ->and(constValue('MAIN_MODULE_USER'))->toBe('1');
});

function serverSeedThirdparties(): void
{
    testDb()->exec("INSERT INTO llx_societe (rowid, nom, email, client, fournisseur, code_client, code_compta, entity) VALUES
        (801, 'Alpha <Corp>', 'a@example.com', 1, 0, 'CU001', '', 1),
        (802, 'Beta Prospect', 'b@example.com', 2, 0, 'CU002', '411OLD', 1),
        (803, 'Gamma Supplier', 'g@example.com', 0, 1, NULL, '', 1),
        (804, 'Other entity', 'o@example.com', 1, 0, 'CU004', '', 2)");
    testDb()->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('SOCIETE_CODECOMPTA_ADDON', 1, 'mod_codecompta_aquarium', 'chaine', 0)");
}

it('lists third parties of the current entity with filters', function (): void {
    serverSeedThirdparties();
    $names = fn (array $args): array => array_column(serverJson(array_merge(['thirdparty', 'list'], $args))['json']['data']['thirdparties'], 'name');

    expect($names([]))->toBe(['Alpha <Corp>', 'Beta Prospect', 'Gamma Supplier'])
        ->and($names(['type=supplier']))->toBe(['Gamma Supplier'])
        ->and($names(['search=beta']))->toBe(['Beta Prospect'])
        ->and($names(['limit=1', 'offset=1']))->toBe(['Beta Prospect'])
        ->and(serverJson(['thirdparty', 'list', 'type=foo'])['code'])->toBe(2);
});

it('previews then writes accounting codes', function (): void {
    serverSeedThirdparties();
    $preview = serverJson(['thirdparty', 'accountingnumber']);
    $write = serverJson(['thirdparty', 'accountingnumber', '--only-empty', '--confirm']);

    expect($preview['json']['data']['written'])->toBeFalse()
        ->and(array_column($preview['json']['data']['thirdparties'], 'new_code', 'id')[801])->toBe('411CU001')
        ->and($write['json']['data']['changed'])->toBe(1)
        ->and(dbValue('SELECT code_compta FROM llx_societe WHERE rowid = 801'))->toBe('411CU001')
        ->and(dbValue('SELECT code_compta FROM llx_societe WHERE rowid = 802'))->toBe('411OLD')
        ->and(dbValue('SELECT code_compta FROM llx_societe WHERE rowid = 804'))->toBe('');
});

it('enables, reports and disables the maintenance mode', function (): void {
    $on = serverJson(['system', 'maintenance', 'enable']);
    $status = serverJson(['system', 'maintenance', 'status']);
    $off = serverJson(['system', 'maintenance', 'disable']);
    $ghost = serverJson(['system', 'maintenance', 'enable', 'login=ghost']);

    expect($on['json']['data']['allowed_login'])->toBe('admin')
        ->and($status['json']['data']['enabled'])->toBeTrue()
        ->and($off['json']['data']['changed'])->toBeTrue()
        ->and($ghost['code'])->toBe(1)
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0)
        ->and(serverJson(['system', 'maintenance'])['code'])->toBe(0);
});

it('sets the cron key from stdin, masks it, shows it on request and clears it', function (): void {
    $set = serverJson(['system', 'cronkey', 'set', '--password-stdin'], "my-secret-key\n");
    $masked = serverJson(['system', 'cronkey', 'show']);
    $shown = serverText(['system', 'cronkey', 'show', '--show-secrets']);
    $clear = serverJson(['system', 'cronkey', 'clear']);
    $after = serverText(['system', 'cronkey', 'show']);

    expect($set['code'])->toBe(0)
        ->and($masked['json']['data']['masked'])->toBeTrue()
        ->and(json_encode($masked['json']))->not->toContain('my-secret-key')
        ->and(trim($shown['out']))->toBe('my-secret-key')
        ->and($clear['code'])->toBe(0)
        ->and($after['out'])->toContain('CRON_KEY is not set');
});

it('generates a random cron key and works on another entity', function (): void {
    $generated = serverJson(['system', 'cronkey', 'generate', '--entity=2']);

    expect($generated['json']['data']['cron_key'])->toMatch('/^[0-9a-f]{32}$/')
        ->and($generated['json']['instance']['entity'])->toBe(2)
        ->and(dbValue("SELECT entity FROM llx_const WHERE name = 'CRON_KEY'"))->toBe(2)
        ->and(serverJson(['system', 'cronkey', 'show'])['json']['data']['set'])->toBeFalse();
});

function serverSignatureFixture(string $indexMd5): string
{
    $file = DOLICLI_TEST_TMP.'/filelist-'.bin2hex(random_bytes(4)).'.xml';
    file_put_contents($file, '<?xml version="1.0"?><checksum_list><dolibarr_htdocs_dir>'
        .'<md5file name="/index.php">'.$indexMd5.'</md5file>'
        .'<dir name="/core"><dir name="lib"><md5file name="functions.lib.php">'.md5_file(DOLICLI_DOLPATH.'/core/lib/functions.lib.php').'</md5file></dir></dir>'
        .'</dolibarr_htdocs_dir></checksum_list>');

    return $file;
}

it('checks the deployed files against a signature', function (): void {
    $intact = serverJson(['system', 'filecheck', 'xmlfile='.serverSignatureFixture((string) md5_file(DOLICLI_DOLPATH.'/index.php'))]);
    $modified = serverJson(['system', 'filecheck', 'xmlfile='.serverSignatureFixture(md5('something else'))]);
    $missing = serverJson(['system', 'filecheck']);

    expect($intact['code'])->toBe(0)
        ->and($intact['json']['data']['summary']['in_signature'])->toBe(2)
        ->and($modified['code'])->toBe(1)
        ->and($modified['json']['errors'][0]['code'])->toBe('FILES_MODIFIED')
        ->and($modified['json']['data']['modified'][0]['filename'])->toBe('/index.php')
        ->and($missing['code'])->toBe(1)
        ->and($missing['json']['errors'][0]['code'])->toBe('SIGNATURE_NOT_FOUND');
});

it('backs up the database, documents and modules with private files', function (): void {
    $dir = DOLICLI_TEST_TMP.'/backup-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    $result = serverJson(['backup', '--database', '--documents', 'dir='.$dir]);
    $files = array_column($result['json']['data']['files'], 'file', 'part');

    expect($result['code'])->toBe(0)
        ->and(filesize($files['database']))->toBe(filesize(DOLICLI_TEST_DB))
        ->and(fileperms($files['database']) & 0777)->toBe(0600)
        ->and(fileperms($files['documents']) & 0777)->toBe(0600)
        ->and(serverJson(['backup'])['code'])->toBe(2);
});

it('runs a repair action on a locked install and puts everything back', function (): void {
    touch(DOLICLI_TEST_DOCUMENTS.'/install.lock');

    $result = serverJson(['repair', 'run', 'action=clean_menus']);

    expect($result['code'])->toBe(0)
        ->and($result['json']['data']['output'])->toContain('menu')
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/install.lock'))->toBeTrue()
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/upgrade.unlock'))->toBeFalse()
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});

it('refuses a second write while the instance lock is held, with exit code 5', function (): void {
    $lock = Dolicli\System\Lock::forDataRoot(DOLICLI_TEST_DOCUMENTS);
    $lock->acquire('system upgrade');

    try {
        $result = serverJson(['system', 'maintenance', 'enable']);
    } finally {
        $lock->release();
    }

    expect($result['code'])->toBe(5)
        ->and($result['json']['errors'][0]['code'])->toBe('LOCKED')
        ->and($result['json']['errors'][0]['message'])->toContain('system upgrade')
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});

it('reports an unreachable database with exit code 3 and never prints the connection details', function (): void {
    $htdocs = DOLICLI_TEST_TMP.'/broken/htdocs';
    mkdir($htdocs.'/conf', 0755, true);
    foreach (scandir(DOLICLI_DOLPATH) as $entry) {
        if ('.' !== $entry && '..' !== $entry && 'conf' !== $entry) {
            symlink(DOLICLI_DOLPATH.'/'.$entry, $htdocs.'/'.$entry);
        }
    }
    file_put_contents($htdocs.'/conf/conf.php', "<?php\n\$dolibarr_main_url_root='http://localhost';\n\$dolibarr_main_document_root='{$htdocs}';\n"
        ."\$dolibarr_main_data_root='".DOLICLI_TEST_TMP."/broken/documents';\n\$dolibarr_main_db_host='127.0.0.1';\n\$dolibarr_main_db_port='1';\n"
        ."\$dolibarr_main_db_name='nodb';\n\$dolibarr_main_db_prefix='llx_';\n\$dolibarr_main_db_user='secretuser';\n\$dolibarr_main_db_pass='secretpass';\n"
        ."\$dolibarr_main_db_type='mysqli';\n");

    $result = serverJson(['user', 'list'], null, ['DOLPATH' => $htdocs]);

    expect($result['code'])->toBe(3)
        ->and($result['json']['errors'][0]['code'])->toBeIn(['DOLIBARR_LOAD_FAILED', 'DATABASE_UNREACHABLE'])
        ->and(json_encode($result['json']).$result['err'])->not->toContain('secretuser');
});

it('reports a missing Dolibarr with exit code 3', function (): void {
    $result = serverJson(['user', 'list'], null, ['DOLPATH' => '/nonexistent/htdocs']);
    $default = serverJson(['user', 'list'], null, ['DOLPATH' => false]);

    expect($result['code'])->toBe(3)
        ->and($result['json']['errors'][0]['code'])->toBe('DOLIBARR_NOT_FOUND')
        ->and($default['code'])->toBe(3)
        ->and($default['json']['errors'][0]['message'])->toContain('./htdocs');
});

it('lists the PHP binaries with their compatibility with the instance', function (): void {
    $result = serverJson(['system', 'php']);
    $binaries = array_column($result['json']['data']['binaries'], null, 'version');

    expect($result['code'])->toBe(0)
        ->and($result['json']['data']['dolibarr_version'])->toBe('18.0.10')
        ->and($result['json']['data']['range']['max'])->toBe('8.1')
        ->and($binaries[PHP_VERSION]['extensions'])->toContain('json');
});

it('discovers the Dolibarr installations without returning credentials', function (): void {
    $result = serverJson(['instance', 'discover', 'root='.dirname(DOLICLI_DOLPATH, 2)]);
    $found = $result['json']['data']['instances'][0];

    expect($result['code'])->toBe(0)
        ->and($found['htdocs'])->toBe(DOLICLI_DOLPATH)
        ->and($found['version'])->toBe('18.0.10')
        ->and($found['documents'])->toBe(realpath(DOLICLI_TEST_DOCUMENTS))
        ->and($found['database_type'])->toBe('sqlite3')
        ->and(json_encode($result['json']))->not->toContain('dolibarr_main_db_pass');
});
