<?php

declare(strict_types=1);

use Dolicli\Dolibarr\Backup as DatabaseBackup;

beforeEach(function (): void {
    $this->bin = DOLICLI_TEST_TMP.'/bin-'.bin2hex(random_bytes(4));
    mkdir($this->bin, 0700);
    $this->oldPath = (string) getenv('PATH');
    putenv('PATH='.$this->bin.PATH_SEPARATOR.$this->oldPath);
    $GLOBALS['conf'] = (object) ['db' => (object) [
        'type' => 'mysqli', 'host' => 'db.example', 'port' => '3307', 'name' => 'dolibarr', 'user' => 'dolu',
    ]];
    $GLOBALS['dolibarr_main_db_pass'] = 's3cr"et';
});

afterEach(function (): void {
    putenv('PATH='.$this->oldPath);
});

function fakeTool(string $dir, string $name, string $body): void
{
    file_put_contents($dir.'/'.$name, "#!/bin/sh\n".$body."\n");
    chmod($dir.'/'.$name, 0700);
}

it('keeps the MySQL password out of the mysqldump command line', function (): void {
    // The fake mysqldump prints its arguments and the option file it was given.
    fakeTool($this->bin, 'mysqldump', 'echo "ARGS: $*"; f=$(echo "$1" | sed "s/--defaults-extra-file=//"); echo "PERMS: $(stat -c %a "$f")"; cat "$f"');
    $target = DOLICLI_TEST_TMP.'/dump-'.bin2hex(random_bytes(4)).'.sql';

    $ok = (new DatabaseBackup())->dumpDatabase($target);

    $dump = (string) file_get_contents($target);
    expect($ok)->toBeTrue()
        ->and(fileperms($target) & 0777)->toBe(0600)
        ->and($dump)->toContain('ARGS: --defaults-extra-file=')
        ->and($dump)->toContain('--single-transaction')
        ->and($dump)->toContain('PERMS: 600')
        ->and($dump)->toContain('password="s3cr\"et"')
        ->and($dump)->toContain('port=3307');
    preg_match('/^ARGS: (.*)$/m', $dump, $m);
    expect($m[1])->not->toContain('s3cr');
    preg_match('/--defaults-extra-file=(\S+)/', $m[1], $f);
    expect(file_exists($f[1]))->toBeFalse();
});

it('reports a failing dump and removes the partial file', function (): void {
    fakeTool($this->bin, 'mysqldump', 'echo "partial"; echo "Access denied" >&2; exit 2');
    $target = DOLICLI_TEST_TMP.'/dump-'.bin2hex(random_bytes(4)).'.sql';

    $backup = new DatabaseBackup();
    $ok = $backup->dumpDatabase($target);

    expect($ok)->toBeFalse()
        ->and($backup->lastError())->toContain('exit code 2')
        ->and($backup->lastError())->toContain('Access denied')
        ->and(file_exists($target))->toBeFalse();
});

it('passes the PostgreSQL password through the environment', function (): void {
    $GLOBALS['conf']->db->type = 'pgsql';
    fakeTool($this->bin, 'pg_dump', 'echo "ARGS: $*"; echo "ENV: $PGPASSWORD"');
    $target = DOLICLI_TEST_TMP.'/dump-'.bin2hex(random_bytes(4)).'.sql';

    $ok = (new DatabaseBackup())->dumpDatabase($target);

    $dump = (string) file_get_contents($target);
    expect($ok)->toBeTrue()
        ->and($dump)->toContain('ENV: s3cr"et')
        ->and($dump)->toContain('ARGS: --no-password -h db.example -U dolu -p 3307 dolibarr');
});

it('refuses an unknown database type', function (): void {
    $GLOBALS['conf']->db->type = 'oracle';
    $target = DOLICLI_TEST_TMP.'/dump-'.bin2hex(random_bytes(4)).'.sql';

    $backup = new DatabaseBackup();

    expect($backup->dumpDatabase($target))->toBeFalse()
        ->and($backup->lastError())->toContain("'oracle' is not supported")
        ->and(file_exists($target))->toBeFalse();
});
