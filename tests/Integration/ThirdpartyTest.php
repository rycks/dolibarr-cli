<?php

declare(strict_types=1);

function seedThirdparties(): void
{
    $db = testDb();
    $db->exec("INSERT INTO llx_societe (rowid, nom, email, client, fournisseur, code_client, code_compta, entity) VALUES
        (801, 'Alpha <Corp>', 'a@example.com', 1, 0, 'CU001', '', 1),
        (802, 'Beta Prospect', 'b@example.com', 2, 0, 'CU002', '411OLD', 1),
        (803, 'Gamma Supplier', 'g@example.com', 0, 1, NULL, '', 1),
        (804, 'Other entity', 'o@example.com', 1, 0, 'CU004', '', 2)");
    $db->exec("INSERT INTO llx_const (name, entity, value, type, visible) VALUES ('SOCIETE_CODECOMPTA_ADDON', 1, 'mod_codecompta_aquarium', 'chaine', 0)");
}

it('lists third parties of the current entity with filters', function (): void {
    seedThirdparties();

    $all = dolicli(['thirdparty', 'list']);
    $suppliers = dolicli(['thirdparty', 'list', 'type=supplier']);
    $search = dolicli(['thirdparty', 'list', 'search=beta']);
    $limited = dolicli(['thirdparty', 'list', 'limit=1', 'offset=1']);

    expect($all['code'])->toBe(0)
        ->and($all['out'])->toContain('Alpha <Corp>')
        ->and($all['out'])->toContain('Gamma Supplier')
        ->and($all['out'])->not->toContain('Other entity')
        ->and($suppliers['out'])->toContain('Gamma Supplier')
        ->and($suppliers['out'])->not->toContain('Alpha')
        ->and($search['out'])->toContain('Beta Prospect')
        ->and($search['out'])->not->toContain('Alpha')
        ->and($limited['out'])->toContain('Beta Prospect')
        ->and($limited['out'])->not->toContain('Alpha');
});

it('rejects an invalid type', function (): void {
    expect(dolicli(['thirdparty', 'list', 'type=foo'])['code'])->toBe(1);
});

it('previews accounting codes without writing them', function (): void {
    seedThirdparties();

    $result = dolicli(['thirdparty', 'accountingnumber']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('Dry run')
        ->and($result['out'])->toMatch('/801\s+Alpha <Corp>\s+411CU001/')
        ->and(dbValue('SELECT code_compta FROM llx_societe WHERE rowid = 801'))->toBe('');
});

it('writes accounting codes with --confirm and filters', function (): void {
    seedThirdparties();

    $result = dolicli(['thirdparty', 'accountingnumber', '--only-empty', '--confirm']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('1 accounting code(s) updated')
        ->and(dbValue('SELECT code_compta FROM llx_societe WHERE rowid = 801'))->toBe('411CU001')
        ->and(dbValue('SELECT code_compta FROM llx_societe WHERE rowid = 802'))->toBe('411OLD')
        ->and(dbValue('SELECT code_compta FROM llx_societe WHERE rowid = 804'))->toBe('');
});
