<?php

declare(strict_types=1);

it('says the database is up to date when it is', function (): void {
    // Test Dolibarr: MAIN_VERSION_LAST_UPGRADE = files version = 18.0.10
    $result = dolicli(['system', 'upgrade', '--skip-backup']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('Database is already at version 18.0.10');
});

it('asks for from= when the database version is unknown', function (): void {
    testDb()->exec("DELETE FROM llx_const WHERE name IN ('MAIN_VERSION_LAST_UPGRADE', 'MAIN_VERSION_LAST_INSTALL')");

    $result = dolicli(['system', 'upgrade', '--skip-backup']);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('give it with from=X.Y.Z');
});

it('rejects an invalid target version', function (): void {
    $result = dolicli(['system', 'upgrade', '--version=latest']);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain("Invalid version 'latest'");
});

it('requires --version with --source', function (): void {
    $result = dolicli(['system', 'upgrade', '--source=/tmp']);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('--source requires --version');
});

it('dumps the database before a migration', function (): void {
    $dir = DOLICLI_TEST_TMP.'/upbackup-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700);

    $result = dolicli(['system', 'upgrade', 'backup-dir='.$dir]);

    expect($result['code'])->toBe(0)
        ->and(glob($dir.'/dolibarr_backup_db_18.0.10_*.sql'))->toHaveCount(1);
});

it('leaves the install locked and maintenance off when a migration step fails', function (): void {
    // Dolibarr cannot migrate a SQLite database: upgrade.php fails, which exercises the error path.
    $result = dolicli(['system', 'upgrade', 'from=17.0.0', '--skip-backup']);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('* 17.0.0 -> 18.0.0')
        ->and($result['out'])->toContain('upgrade.php failed')
        ->and($result['out'])->toContain('Disabling maintenance mode')
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/install.lock'))->toBeTrue()
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/upgrade.unlock'))->toBeFalse()
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});
