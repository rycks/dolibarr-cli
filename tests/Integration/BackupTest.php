<?php

declare(strict_types=1);

function backupDir(): string
{
    $dir = DOLICLI_TEST_TMP.'/backup-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    return $dir;
}

it('requires at least one backup kind', function (): void {
    $result = dolicli(['backup']);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('Choose at least one of');
});

it('dumps the database in a private file', function (): void {
    $dir = backupDir();

    $result = dolicli(['backup', '--database', 'dir='.$dir]);

    $files = glob($dir.'/*-database.sql');
    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('Database exported in')
        ->and($files)->toHaveCount(1)
        ->and(fileperms($files[0]) & 0777)->toBe(0600)
        ->and(file_get_contents($files[0], false, null, 0, 15))->toBe('SQLite format 3');
});

it('zips the documents without log files', function (): void {
    $dir = backupDir();
    $logFile = dirname(DOLICLI_TEST_DB).'/dolicli-test.log';
    file_put_contents($logFile, 'log');

    try {
        $result = dolicli(['backup', '--documents', 'dir='.$dir]);
    } finally {
        unlink($logFile);
    }

    $files = glob($dir.'/*-documents.zip');
    expect($result['code'])->toBe(0)->and($files)->toHaveCount(1);
    $zip = new ZipArchive();
    $zip->open($files[0]);
    expect($zip->locateName('database_dolibarr.sdb'))->not->toBeFalse()
        ->and($zip->locateName('dolicli-test.log'))->toBeFalse()
        ->and(fileperms($files[0]) & 0777)->toBe(0600);
    $zip->close();
});

it('zips the custom modules directory', function (): void {
    $dir = backupDir();

    $result = dolicli(['backup', '--modules', 'dir='.$dir]);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('/custom')
        ->and(glob($dir.'/*-modules.zip'))->toHaveCount(1);
});

it('never passes the destination to a shell', function (): void {
    $marker = DOLICLI_TEST_TMP.'/pwned';
    $result = dolicli(['backup', '--database', 'dir=/tmp; touch '.$marker]);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('not writable')
        ->and(file_exists($marker))->toBeFalse();
});
