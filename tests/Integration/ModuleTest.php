<?php

declare(strict_types=1);

function moduleConst(string $name): mixed
{
    return dbValue('SELECT value FROM llx_const WHERE name = ? AND entity IN (0, 1)', [$name]);
}

it('lists modules with their activation state', function (): void {
    $result = dolicli(['module', 'list']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toMatch('/modUser\s+\d+\s+Users.*\byes\b/')
        ->and($result['out'])->not->toContain('&amp;');
});

it('activates and deactivates a module and reports it correctly', function (): void {
    expect(moduleConst('MAIN_MODULE_PROJET'))->toBeFalse();

    $on = dolicli(['module', 'activate', 'name=Projet']);
    expect($on['code'])->toBe(0)
        ->and($on['out'])->toContain("Module 'modProjet' activated.")
        ->and(moduleConst('MAIN_MODULE_PROJET'))->toBe('1');

    $again = dolicli(['module', 'activate', 'name=modProjet']);
    expect($again['out'])->toContain('already active');

    $off = dolicli(['module', 'deactivate', 'name=modProjet']);
    expect($off['code'])->toBe(0)
        ->and($off['out'])->toContain("Module 'modProjet' deactivated.")
        ->and(moduleConst('MAIN_MODULE_PROJET'))->toBeFalse();
});

it('fails cleanly on an unknown module', function (): void {
    $result = dolicli(['module', 'activate', 'name=modNoSuchThing']);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain("Module 'modNoSuchThing' not found")
        ->and($result['err'])->toBe('');
});

it('asks before deactivating every module', function (): void {
    $result = dolicli(['module', 'deactivate', '--all'], '');

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('use --yes')
        ->and(moduleConst('MAIN_MODULE_EXPORT'))->toBe('1');
});

it('deactivates every module but the protected ones with --yes', function (): void {
    $result = dolicli(['module', 'deactivate', '--all', '--yes']);

    expect($result['code'])->toBe(0)
        ->and(moduleConst('MAIN_MODULE_EXPORT'))->toBeFalse()
        ->and(moduleConst('MAIN_MODULE_IMPORT'))->toBeFalse()
        ->and(moduleConst('MAIN_MODULE_USER'))->toBe('1');
});
