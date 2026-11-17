<?php

declare(strict_types=1);

use App\Util\FileSignature;

it('reports missing and modified files', function (): void {
    $root = DOLICLI_TEST_TMP.'/sig-'.bin2hex(random_bytes(4));
    mkdir($root.'/core', 0700, true);
    file_put_contents($root.'/index.php', 'index');
    file_put_contents($root.'/core/a.php', 'changed');

    $xml = '<?xml version="1.0"?><checksum_list><dolibarr_htdocs_dir>'
        .'<md5file name="/index.php">'.md5('index').'</md5file>'
        .'<md5file name="/gone.php">'.md5('gone').'</md5file>'
        .'<dir name="/core"><md5file name="a.php">'.md5('original').'</md5file></dir>'
        .'</dolibarr_htdocs_dir></checksum_list>';

    $result = FileSignature::compare(FileSignature::parse($xml), $root);

    expect(array_column($result['ok'], 'filename'))->toBe(['/index.php'])
        ->and(array_column($result['missing'], 'filename'))->toBe(['/gone.php'])
        ->and(array_column($result['updated'], 'filename'))->toBe(['/core/a.php'])
        ->and($result['insignature'])->toHaveCount(3);
});

it('rejects an invalid signature', function (): void {
    expect(FileSignature::parse('<nope'))->toBeNull()
        ->and(FileSignature::parse('<?xml version="1.0"?><other/>'))->toBeNull();
});
