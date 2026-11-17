<?php

declare(strict_types=1);

it('fails with a non-zero code when Dolibarr is not found', function (): void {
    $result = dolicli(['user', 'list'], null, ['DOLPATH' => '/nonexistent/htdocs']);

    expect($result['code'])->toBe(1)
        ->and($result['err'])->toContain("Dolibarr not found in '/nonexistent/htdocs'");
});

it('falls back to ./htdocs when DOLPATH is not set', function (): void {
    $result = dolicli(['user', 'list'], null, ['DOLPATH' => false]);

    expect($result['code'])->toBe(1)
        ->and($result['err'])->toContain("Dolibarr not found in './htdocs'");
});

it('exits with 1 on an unknown command', function (): void {
    $result = dolicli(['nosuchcommand']);

    expect($result['code'])->toBe(1)
        ->and($result['err'])->toContain('Command not found');
});

it('does not strip angle brackets from command output', function (): void {
    testDb()->exec("UPDATE llx_user SET lastname = 'A <b> C' WHERE login = 'admin'");

    $result = dolicli(['user', 'list']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('A <b> C');
});

it('exits with the code set by the command', function (): void {
    $result = dolicli(['system', 'filecheck', 'xmlfile=/nonexistent.xml']);

    expect($result['code'])->toBe(3);
});

it('shows the general help, built from the commands, without argument', function (): void {
    $result = dolicli([]);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('Dolibarr tools from command line')
        ->and($result['out'])->toContain('./dolibarr system upgrade')
        ->and($result['out'])->toContain('- Check deployed files against the official signature')
        ->and($result['out'])->toContain('./dolibarr backup ')
        ->and($result['out'])->toContain('entity=N')
        ->and(dolicli(['help'])['out'])->toBe($result['out']);
});
