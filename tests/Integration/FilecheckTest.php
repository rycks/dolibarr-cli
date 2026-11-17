<?php

declare(strict_types=1);

function signatureFixture(string $indexMd5): string
{
    $file = DOLICLI_TEST_TMP.'/filelist-'.bin2hex(random_bytes(4)).'.xml';
    file_put_contents($file, '<?xml version="1.0"?><checksum_list><dolibarr_htdocs_dir>'
        .'<md5file name="/index.php">'.$indexMd5.'</md5file>'
        .'<dir name="/core"><dir name="lib"><md5file name="functions.lib.php">'.md5_file(DOLICLI_DOLPATH.'/core/lib/functions.lib.php').'</md5file></dir></dir>'
        .'</dolibarr_htdocs_dir></checksum_list>');

    return $file;
}

it('accepts the documented --xmlfile= form and passes on intact files', function (): void {
    $xml = signatureFixture((string) md5_file(DOLICLI_DOLPATH.'/index.php'));

    $result = dolicli(['system', 'filecheck', '--xmlfile='.$xml]);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('In signature: 2')
        ->and($result['out'])->toContain('Deployment integrity OK.');
});

it('outputs clean JSON with --format=json and exits 1 on a modified file', function (): void {
    $xml = signatureFixture(md5('something else'));

    $result = dolicli(['system', 'filecheck', '--xmlfile='.$xml, '--format=json']);

    $json = json_decode($result['out'], true);
    expect($result['code'])->toBe(1)
        ->and($json)->toBeArray()
        ->and($json['summary']['modified'])->toBe(1)
        ->and($json['modified'][0]['filename'])->toBe('/index.php');
});

it('exits 3 without signature', function (): void {
    $result = dolicli(['system', 'filecheck']);

    expect($result['code'])->toBe(3)
        ->and($result['out'])->toContain('Use --remote');
});
