<?php

declare(strict_types=1);

function seedPersonalData(): void
{
    $db = testDb();
    $db->exec("INSERT INTO llx_societe (rowid, nom, address, zip, town, email, phone, siren, note_private, entity, client)
        VALUES (9001, 'Real Company SARL', '12 rue Secrete', '99999', 'Realville', 'boss@real-company.fr', '0102030405', '123456789', 'private note about the client', 1, 1)");
    $db->exec("INSERT INTO llx_socpeople (rowid, lastname, firstname, email, address, fk_soc, entity)
        VALUES (9002, 'Realname', 'Jeanne', 'jeanne@real-company.fr', '3 impasse Privee', 9001, 1)");
    $db->exec("INSERT INTO llx_user (rowid, login, lastname, firstname, email, pass_crypted, api_key, entity, statut, admin)
        VALUES (9003, 'jdoe', 'Doe', 'John', 'john@real-company.fr', '\$2y\$10\$realhash', 'realapikey', 1, 1, 0)");
    $db->exec("INSERT INTO llx_societe_rib (rowid, fk_soc, iban_prefix, bic, proprio, label) VALUES (9004, 9001, 'FR7612345678901234567890123', 'REALBICX', 'Real Owner', 'main')");
    $db->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAIN_MAIL_SMTPS_PW', 1, 'smtp-password', 'chaine', 0)");
    $db->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('MAIN_SECURITY_CSRF_WITH_TOKEN', 1, '2', 'chaine', 0)");
    $db->exec("INSERT INTO llx_blockedlog (rowid, entity, action, amounts, signature, signature_line, element, fk_object, ref_object, object_data, user_fullname, fk_user, date_creation, certified)
        VALUES (9005, 1, 'BILL_VALIDATE', 10, 'sig', 'sigline', 'facture', 1, 'FA1', 'data', 'John Doe', 1, '2026-01-01 00:00:00', 0)");
}

it('only shows what would change in dry run', function (): void {
    seedPersonalData();

    $result = dolicli(['anonymize', 'run']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('DRY RUN')
        ->and($result['out'])->toContain('--confirm --this-is-a-copy')
        ->and(dbValue('SELECT nom FROM llx_societe WHERE rowid = 9001'))->toBe('Real Company SARL');
});

it('refuses to run without --this-is-a-copy', function (): void {
    $result = dolicli(['anonymize', 'run', '--confirm'], "YES\n");

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('--this-is-a-copy');
});

it('aborts without YES on standard input', function (): void {
    seedPersonalData();

    $result = dolicli(['anonymize', 'run', '--confirm', '--this-is-a-copy'], '');

    expect($result['out'])->toContain('Aborted.')
        ->and(dbValue('SELECT nom FROM llx_societe WHERE rowid = 9001'))->toBe('Real Company SARL');
});

it('anonymizes personal data and keeps the admin', function (): void {
    seedPersonalData();
    $adminBefore = testDb()->query("SELECT lastname, email, pass_crypted FROM llx_user WHERE login = 'admin'")->fetch(PDO::FETCH_ASSOC);

    $result = dolicli(['anonymize', 'run', '--confirm', '--this-is-a-copy'], "YES\n");

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('Anonymization completed.');

    $dump = '';
    foreach (['llx_societe', 'llx_socpeople', 'llx_user', 'llx_societe_rib', 'llx_const'] as $table) {
        foreach (testDb()->query("SELECT * FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dump .= implode('|', array_map('strval', $row))."\n";
        }
    }
    foreach (['Real Company', 'rue Secrete', 'Realville', 'real-company.fr', '0102030405', '123456789', 'private note',
        'Realname', 'Privee', 'jdoe', 'realhash', 'realapikey', 'FR7612345678901234567890123', 'REALBICX', 'Real Owner', 'smtp-password'] as $secret) {
        expect($dump)->not->toContain($secret);
    }

    expect(testDb()->query("SELECT lastname, email, pass_crypted FROM llx_user WHERE login = 'admin'")->fetch(PDO::FETCH_ASSOC))->toBe($adminBefore)
        ->and(dbValue('SELECT pass_crypted FROM llx_user WHERE rowid = 9003'))->toBeNull()
        ->and(dbValue("SELECT value FROM llx_const WHERE name = 'MAIN_SECURITY_CSRF_WITH_TOKEN'"))->toBe('2')
        ->and(dbValue('SELECT COUNT(*) FROM llx_blockedlog'))->toBe(0)
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});

it('rolls everything back when a statement fails', function (): void {
    seedPersonalData();
    testDb()->exec("CREATE TRIGGER fail_contacts BEFORE UPDATE ON llx_socpeople BEGIN SELECT RAISE(ABORT, 'forced failure'); END");

    $result = dolicli(['anonymize', 'run', '--confirm', '--this-is-a-copy'], "YES\n");

    expect($result['code'])->toBe(1)
        ->and($result['out'])->toContain('every change was rolled back')
        ->and(dbValue('SELECT nom FROM llx_societe WHERE rowid = 9001'))->toBe('Real Company SARL')
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});
