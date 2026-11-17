<?php

declare(strict_types=1);

it('runs a repair action on a locked install and locks it again', function (): void {
    touch(DOLICLI_TEST_DOCUMENTS.'/install.lock');

    $result = dolicli(['repair', 'run', 'action=clean_menus', 'mode=test']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('clean_menus=test')
        ->and($result['out'])->toContain("Option clean_menus ('test' or 'confirmed') is test")
        ->and($result['out'])->toContain("Repair action 'clean_menus' completed")
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/install.lock'))->toBeTrue()
        ->and(file_exists(DOLICLI_TEST_DOCUMENTS.'/upgrade.unlock'))->toBeFalse()
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});

it('passes the option name repair.php expects for force_disable_modules', function (): void {
    $result = dolicli(['repair', 'run', 'action=force_disable_modules']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain("Option force_disable_of_modules_not_found ('test' or 'confirmed') is test");
});

it('keeps an existing maintenance mode', function (): void {
    testDb()->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAIN_ONLY_LOGIN_ALLOWED', 1, 'admin', 'chaine', 0)");

    $result = dolicli(['repair', 'run', 'action=clean_menus']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->not->toContain('Disabling maintenance mode')
        ->and(dbValue("SELECT value FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe('admin');
});

it('rejects unknown actions and modes', function (): void {
    expect(dolicli(['repair', 'run', 'action=nope'])['code'])->toBe(1)
        ->and(dolicli(['repair', 'run', 'action=clean_menus', 'mode=yes'])['code'])->toBe(1)
        ->and(dolicli(['repair', 'run'])['code'])->toBe(1);
});
