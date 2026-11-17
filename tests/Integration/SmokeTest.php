<?php

declare(strict_types=1);

it('lists the users of the test Dolibarr', function (): void {
    $result = dolicli(['user', 'list']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('admin')
        ->and($result['out'])->toContain('SuperAdmin');
});
