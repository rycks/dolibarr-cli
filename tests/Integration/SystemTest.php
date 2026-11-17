<?php

declare(strict_types=1);

it('enables, reports and disables maintenance mode', function (): void {
    $on = dolicli(['system', 'maintenance', 'enable']);
    $status = dolicli(['system', 'maintenance', 'status']);
    $off = dolicli(['system', 'maintenance', 'disable']);

    expect($on['code'])->toBe(0)
        ->and($on['out'])->toContain("Only user 'admin'")
        ->and($status['out'])->toContain('ENABLED')
        ->and($off['code'])->toBe(0)
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0);
});

it('refuses maintenance for an unknown user and an unknown action', function (): void {
    expect(dolicli(['system', 'maintenance', 'enable', 'login=ghost'])['code'])->toBe(1)
        ->and(dbValue("SELECT COUNT(*) FROM llx_const WHERE name = 'MAIN_ONLY_LOGIN_ALLOWED'"))->toBe(0)
        ->and(dolicli(['system', 'maintenance'])['code'])->toBe(1)
        ->and(dolicli(['system', 'cronkey', 'nope'])['code'])->toBe(1);
});

it('sets, shows and clears the cron key', function (): void {
    $set = dolicli(['system', 'cronkey', 'set', 'key=my-secret-key']);
    $show = dolicli(['system', 'cronkey', 'show']);
    $clear = dolicli(['system', 'cronkey', 'clear']);
    $after = dolicli(['system', 'cronkey', 'show']);

    expect($set['code'])->toBe(0)
        ->and($set['out'])->toContain('cron_run_jobs_by_url.php?securitykey=my-secret-key')
        ->and(trim($show['out']))->toBe('my-secret-key')
        ->and($clear['out'])->toContain('can no longer be triggered by URL')
        ->and($after['out'])->toContain('CRON_KEY is not set');
});

it('generates a random cron key', function (): void {
    $result = dolicli(['system', 'cronkey', 'generate']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toMatch('/^[0-9a-f]{32}$/m');
});

it('works on another entity with the global entity= option', function (): void {
    dolicli(['system', 'cronkey', 'set', 'key=entity-two', 'entity=2']);

    expect(dbValue("SELECT entity FROM llx_const WHERE name = 'CRON_KEY'"))->toBe(2)
        ->and(dolicli(['system', 'cronkey', 'show'])['out'])->toContain('CRON_KEY is not set')
        ->and(trim(dolicli(['system', 'cronkey', 'show', 'entity=2'])['out']))->toBe('entity-two');
});
