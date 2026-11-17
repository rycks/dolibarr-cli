<?php

declare(strict_types=1);

use Dolicli\Cli\Args;
use Dolicli\Cli\ExitCode;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;
use Dolicli\Cli\Text;
use Dolicli\Instance\Instance;
use Dolicli\Instance\InstancesIni;
use Dolicli\Instance\PhpFileReader;
use Dolicli\System\Lock;
use Dolicli\System\Posix;

it('parses words, key=value, --key=value and flags', function (): void {
    $args = new Args(['user', 'add', 'login=jdoe', '--email=a@b.c', '--yes', '--dry-run', 'backup_dir=/x'], true);

    expect($args->words())->toBe(['user', 'add'])
        ->and($args->value('login'))->toBe('jdoe')
        ->and($args->value('email'))->toBe('a@b.c')
        ->and($args->value('backup-dir'))->toBe('/x')
        ->and($args->flag('yes'))->toBeTrue()
        ->and($args->flag('force'))->toBeFalse()
        ->and($args->givenOnCommandLine('login'))->toBeTrue()
        ->and($args->shiftWords(2)->words())->toBe([])
        ->and($args->describe(['login']))->toContain('login=***');
});

it('knows when values come from the stream of the ephemeral mode', function (): void {
    $args = new Args(['user', 'password', 'newpass=secret'], false);

    expect($args->value('newpass'))->toBe('secret')
        ->and($args->givenOnCommandLine('newpass'))->toBeFalse();
});

it('reports invalid dash arguments', function (): void {
    expect((new Args(['-x', '--', 'list'], true))->invalid())->toBe(['-x', '--']);
});

it('turns latin1 bytes into UTF-8 and encodes without failing', function (): void {
    $latin1 = "Caf\xE9 \xAB ok \xBB";

    expect(Json::utf8($latin1))->toBe('Café « ok »')
        ->and(Json::utf8('déjà valide'))->toBe('déjà valide')
        ->and(Json::encode(['name' => $latin1, 'ratio' => INF], false))->toBe('{"name":"Café « ok »","ratio":null}')
        ->and(Json::encode(Json::object([]), false))->toBe('{}');
});

it('removes control characters from text output', function (): void {
    expect(Text::safe("evil\e[31mred\x07\xC2\x9B"))->toBe('evil[31mred')
        ->and(Text::width('été'))->toBe(3)
        ->and(Text::table(['A', 'B'], [['été', 1]]))->toBe("A    B\n---  -\nété  1\n")
        ->and(Text::mask('0123456789abcdef'))->toStartWith('0123...');
});

it('reads conf.php without executing it, including dirname(__DIR__) expressions', function (): void {
    $reader = PhpFileReader::fromFile(DOLICLI_DOLPATH.'/conf/conf.php');
    $filefunc = PhpFileReader::fromFile(DOLICLI_DOLPATH.'/filefunc.inc.php');

    expect($reader)->not->toBeNull()
        ->and($reader?->variable('dolibarr_main_db_type'))->toBe('sqlite3')
        ->and(realpath((string) $reader?->variable('dolibarr_main_data_root')))->toBe(realpath(DOLICLI_TEST_DOCUMENTS))
        ->and($filefunc?->constant('DOL_VERSION'))->toBe('18.0.10')
        ->and($filefunc?->variable('conffile'))->toBe('conf/conf.php');
});

it('never evaluates calls or variables in conf.php', function (): void {
    $code = '<?php $a = system("id"); $b = "x$y"; $c = \'ok\' . "\\n"; if (1) { $d = 1; } $e = dirname("/a/b/c", 2);';
    $reader = PhpFileReader::fromCode($code, '/srv/conf/conf.php');

    expect($reader->hasVariable('a'))->toBeFalse()
        ->and($reader->hasVariable('b'))->toBeFalse()
        ->and($reader->variable('c'))->toBe("ok\n")
        ->and($reader->hasVariable('d'))->toBeFalse()
        ->and($reader->variable('e'))->toBe('/a');
});

it('reads instances.ini and rejects invalid declarations', function (): void {
    $file = DOLICLI_TEST_TMP.'/instances.ini';
    file_put_contents($file, "[client-a]\nhtdocs = /var/www/a/htdocs\nrun_as = www-data\nphp = /usr/bin/php7.4\n\n[all]\nhtdocs = /x\n\n[Bad Id]\nhtdocs = /y\n\n[rel]\nhtdocs = var/www\n\n[odd]\nhtdocs = /z\nfoo = 1\n");
    $ini = InstancesIni::load($file);

    expect(array_keys($ini->all()))->toBe(['client-a', 'odd'])
        ->and($ini->get('client-a')?->setting('run_as'))->toBe('www-data')
        ->and($ini->get('client-a')?->setting('php'))->toBe('/usr/bin/php7.4')
        ->and(implode("\n", $ini->errors()))->toContain("Invalid instance identifier 'all'")
        ->and(implode("\n", $ini->errors()))->toContain("Unknown key 'foo'")
        ->and(implode("\n", $ini->errors()))->toContain('[rel]: htdocs must be an absolute path')
        ->and(InstancesIni::render($ini->all()))->toContain("[client-a]\nhtdocs = \"/var/www/a/htdocs\"\nrun_as = \"www-data\"");
});

it('validates instance identifiers', function (): void {
    expect(Instance::isValidId('client-a_prod2'))->toBeTrue()
        ->and(Instance::isValidId('all'))->toBeFalse()
        ->and(Instance::isValidId('-x'))->toBeFalse()
        ->and(Instance::isValidId('a/b'))->toBeFalse()
        ->and(Instance::idFromHtdocs('/var/www/Client A/htdocs'))->toBe('client-a');
});

it('refuses a second holder of the instance lock with exit code 5 and names the first', function (): void {
    $dir = DOLICLI_TEST_TMP.'/lockdata';
    $first = Lock::forDataRoot($dir);
    $first->acquire('system upgrade');
    $second = Lock::forDataRoot($dir);

    try {
        $second->acquire('user add');
        $this->fail('second lock acquired');
    } catch (Failure $failure) {
        expect($failure->exitCode())->toBe(ExitCode::LOCKED)
            ->and($failure->getMessage())->toContain('system upgrade')
            ->and($first->holder()['command'] ?? null)->toBe('system upgrade');
    }
    $first->release();

    expect($second->holder())->toBe([]);
    $second->acquire('user add');
    $second->release();
});

it('reads identities without the posix extension', function (): void {
    $dir = DOLICLI_TEST_TMP.'/ids';
    @mkdir($dir);
    file_put_contents($dir.'/status', "Name:\tphp\nUid:\t1000\t33\t33\t33\nGid:\t1000\t44\t44\t44\n");
    file_put_contents($dir.'/passwd', "root:x:0:0:root:/root:/bin/bash\nwww-data:x:33:33:www-data:/var/www:/usr/sbin/nologin\n");
    file_put_contents($dir.'/group', "root:x:0:\nmonitor:x:44:zabbix\n");
    Posix::useFiles($dir.'/status', $dir.'/passwd', $dir.'/group');

    try {
        $method = new ReflectionMethod(Posix::class, 'procId');
        expect($method->invoke(null, 'Uid'))->toBe(33)
            ->and($method->invoke(null, 'Gid'))->toBe(44)
            ->and(Posix::isValidAccountName('www-data'))->toBeTrue()
            ->and(Posix::isValidAccountName('a;b'))->toBeFalse();
    } finally {
        Posix::useFiles('/proc/self/status', '/etc/passwd', '/etc/group');
    }
});
