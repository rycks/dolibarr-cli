<?php

declare(strict_types=1);

it('creates a user with a password read on stdin, without echoing it', function (): void {
    $result = dolicli(['user', 'add', 'login=jdoe', 'firstname=John', 'lastname=Doe', 'email=j@example.com', '--password-stdin'], "S3cure-pass-42\n");

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain("User 'jdoe' created")
        ->and($result['out'])->not->toContain('S3cure-pass-42')
        ->and(password_verify('S3cure-pass-42', (string) dbValue("SELECT pass_crypted FROM llx_user WHERE login = 'jdoe'")))->toBeTrue();
});

it('does not leave a user behind when the password is rejected', function (): void {
    testDb()->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('USER_PASSWORD_GENERATED', 1, 'Perso', 'chaine', 0)");
    testDb()->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('USER_PASSWORD_PATTERN', 1, '12;1;1;1;3;1', 'chaine', 0)");

    $result = dolicli(['user', 'add', 'login=weak', 'firstname=W', 'lastname=K', '--password-stdin'], "abc\n");

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('password rejected')
        ->and(dbValue("SELECT COUNT(*) FROM llx_user WHERE login = 'weak'"))->toBe(0);
});

it('requires login, firstname and lastname', function (): void {
    $result = dolicli(['user', 'add', 'login=x']);

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain("Parameter 'firstname' is required");
});

it('changes a password without printing it', function (): void {
    $result = dolicli(['user', 'password', 'login=admin', '--password-stdin', '--force'], "N3w-admin-pass\n");

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain("New password set for 'admin'")
        ->and($result['out'])->not->toContain('N3w-admin-pass')
        ->and(password_verify('N3w-admin-pass', (string) dbValue("SELECT pass_crypted FROM llx_user WHERE login = 'admin'")))->toBeTrue();
});

it('fails on an unknown login', function (): void {
    expect(dolicli(['user', 'password', 'login=nobody', '--password-stdin'], "x\n")['code'])->toBe(1)
        ->and(dolicli(['user', 'show', 'login=nobody'])['code'])->toBe(1)
        ->and(dolicli(['user', 'edit', 'login=nobody', '--lock'])['code'])->toBe(1)
        ->and(dolicli(['user', 'password'])['code'])->toBe(1);
});

it('locks and unlocks an account', function (): void {
    dolicli(['user', 'add', 'login=lockme', 'firstname=L', 'lastname=M', '--password-stdin'], "Lock-me-123456\n");

    expect(dolicli(['user', 'edit', 'login=lockme', '--lock'])['code'])->toBe(0)
        ->and(dbValue("SELECT statut FROM llx_user WHERE login = 'lockme'"))->toBe(0)
        ->and(dolicli(['user', 'edit', 'login=lockme', '--unlock'])['code'])->toBe(0)
        ->and(dbValue("SELECT statut FROM llx_user WHERE login = 'lockme'"))->toBe(1)
        ->and(dolicli(['user', 'edit', 'login=lockme'])['code'])->toBe(1);
});

it('shows groups and super admin status', function (): void {
    testDb()->exec("INSERT INTO llx_usergroup (rowid, nom, entity) VALUES (900, 'Accountants', 1)");
    $adminId = dbValue("SELECT rowid FROM llx_user WHERE login = 'admin'");
    testDb()->exec("INSERT INTO llx_usergroup_user (fk_user, fk_usergroup, entity) VALUES ({$adminId}, 900, 1)");

    $result = dolicli(['user', 'show', 'login=admin']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('- Accountants')
        ->and($result['out'])->toContain('1 group(s)')
        ->and($result['out'])->toContain('Super admin:     Yes');
});

it('lists users with status and admin level', function (): void {
    $result = dolicli(['user', 'list']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toMatch('/admin\s+SuperAdmin.*enabled\s+superadmin/');
});

it('generates an API key once and does not show an existing one', function (): void {
    $first = dolicli(['user', 'token', 'login=admin']);
    preg_match('/^([0-9a-f]{40})$/m', $first['out'], $m);

    $second = dolicli(['user', 'token', 'login=admin']);
    $forced = dolicli(['user', 'token', 'login=admin', '--force']);

    expect($first['code'])->toBe(0)
        ->and($m)->toHaveCount(2)
        ->and($second['code'])->toBe(1)
        ->and($second['out'])->not->toContain($m[1])
        ->and($second['out'])->toContain('was NOT modified')
        ->and($forced['code'])->toBe(0)
        ->and($forced['out'])->toContain('the previous key no longer works')
        ->and($forced['out'])->not->toContain($m[1]);
});
