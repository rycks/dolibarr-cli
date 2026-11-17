<?php

declare(strict_types=1);

use App\Util\InstallLock;

function fakeInstall(bool $knowsUnlock, bool $locked): array
{
    $root = DOLICLI_TEST_TMP.'/install-'.bin2hex(random_bytes(4));
    mkdir($root.'/htdocs/install', 0700, true);
    mkdir($root.'/documents', 0700);
    file_put_contents($root.'/htdocs/install/inc.php', $knowsUnlock ? '<?php $f = "upgrade.unlock";' : '<?php');
    if ($locked) {
        touch($root.'/documents/install.lock');
    }

    return [$root.'/documents', $root.'/htdocs'];
}

it('opens only the upgrade pages when upgrade.unlock is supported', function (): void {
    [$data, $docs] = fakeInstall(true, true);
    $lock = new InstallLock($data, $docs);

    expect($lock->open())->toBe('unlock')
        ->and(file_exists($data.'/install.lock'))->toBeTrue()
        ->and(file_exists($data.'/upgrade.unlock'))->toBeTrue();

    $lock->close();
    expect(file_exists($data.'/upgrade.unlock'))->toBeFalse()
        ->and(file_exists($data.'/install.lock'))->toBeTrue();
});

it('removes then restores install.lock on old versions', function (): void {
    [$data, $docs] = fakeInstall(false, true);
    $lock = new InstallLock($data, $docs);

    expect($lock->open())->toBe('removed')
        ->and(file_exists($data.'/install.lock'))->toBeFalse();

    $lock->close();
    $lock->close();
    expect(file_exists($data.'/install.lock'))->toBeTrue();
});

it('always leaves a lock after close', function (): void {
    [$data, $docs] = fakeInstall(true, false);
    $lock = new InstallLock($data, $docs);
    $lock->open();
    $lock->close();

    expect($lock->isLocked())->toBeTrue();
});
