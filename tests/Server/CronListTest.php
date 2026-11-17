<?php

declare(strict_types=1);

/**
 * Scheduled jobs covering every case: ok, failed, late, stuck, disabled, another entity, entity 0.
 */
function serverSeedCronJobs(int $now): void
{
    $date = fn (int $offset): string => date('Y-m-d H:i:s', $now + $offset);
    $insert = testDb()->prepare('INSERT INTO llx_cronjob (rowid, jobtype, label, frequency, unitfrequency, status, processing, datelastrun, datenextrun, lastresult, lastoutput, entity)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $rows = [
        [901, 'method', 'Backup nightly', 1, '86400', 1, 0, $date(-3600), $date(86400), '0', "Dump done\nsecond line", 1],
        [902, 'method', 'Send reminders', 5, '60', 1, 0, $date(-600), $date(300), '-1', "  \n\tError: SMTP refused ".str_repeat('x', 300), 1],
        [903, 'method', 'Alpha late', 2, '3600', 1, 0, $date(-4 * 86400), $date(-3 * 86400), '0', '', 1],
        [904, 'command', 'Zeta stuck', 1, '604800', 1, 1, $date(-2 * 86400), $date(3600), null, null, 1],
        [905, 'method', 'Disabled failed', 1, '2678400', 0, 0, $date(-5 * 86400), $date(-3 * 86400), '1', 'Old error', 1],
        [906, 'method', 'Other entity', 1, '86400', 1, 0, $date(-5 * 86400), $date(-3 * 86400), '1', 'Not ours', 2],
        [907, 'function', 'Global job', 90, '1', 1, 0, null, null, '', '', 0],
    ];
    foreach ($rows as $row) {
        $insert->execute($row);
    }
}

it('lists the scheduled jobs, problems first, with the definitions of the monitor', function (): void {
    $now = time();
    serverSeedCronJobs($now);

    $result = serverJson(['cron', 'list']);
    $data = $result['json']['data'];
    $jobs = array_column($data['jobs'], null, 'id');
    $state = json_decode(server(['monitor', 'collect', '--stdout'])['out'], true);

    expect($result['code'])->toBe(0)
        ->and(array_column($data['jobs'], 'id'))->toBe([904, 902, 903, 901, 905, 907])
        ->and([$data['count'], $data['failed'], $data['late'], $data['stuck']])->toBe([6, 1, 1, 1])
        ->and([$state['checks']['cron.failed']['value'], $state['checks']['cron.stale']['value'], $state['checks']['cron.stuck']['value']])->toBe([1, 1, 1])
        ->and($jobs[901])->toMatchArray([
            'label' => 'Backup nightly', 'status' => 'enabled', 'frequency' => 'every 1 day', 'last_result' => 'ok', 'last_output' => 'Dump done',
            'processing' => false, 'failed' => false, 'late' => false, 'stuck' => false,
        ])
        ->and(strtotime($jobs[901]['last_run']))->toBe($now - 3600)
        ->and(strtotime($jobs[901]['next_run']))->toBe($now + 86400)
        ->and($jobs[901]['last_run'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/')
        ->and($jobs[902])->toMatchArray(['frequency' => 'every 5 minutes', 'last_result' => 'failed', 'failed' => true, 'late' => false])
        ->and($jobs[902]['last_output'])->toBe(mb_substr('Error: SMTP refused '.str_repeat('x', 300), 0, 200))
        ->and($jobs[903])->toMatchArray(['frequency' => 'every 2 hours', 'late' => true, 'failed' => false, 'last_output' => ''])
        ->and($jobs[904])->toMatchArray(['frequency' => 'every 1 week', 'processing' => true, 'stuck' => true, 'last_result' => null, 'last_output' => ''])
        ->and($jobs[905])->toMatchArray(['status' => 'disabled', 'frequency' => 'every 1 month', 'last_result' => 'failed', 'failed' => false, 'late' => false])
        ->and($jobs[907])->toMatchArray(['frequency' => 'every 90 seconds', 'last_run' => null, 'next_run' => null, 'last_result' => null])
        ->and($jobs)->not->toHaveKey(906);
});

it('filters the scheduled jobs with flags combined as OR, totals unchanged', function (): void {
    serverSeedCronJobs(time());

    $failed = serverJson(['cron', 'list', '--failed'])['json']['data'];
    $lateOrStuck = serverJson(['cron', 'list', '--late', '--stuck'])['json']['data'];
    $text = server(['cron', 'list', '--stuck', '--format=text']);

    expect(array_column($failed['jobs'], 'id'))->toBe([902])
        ->and([$failed['count'], $failed['failed'], $failed['late'], $failed['stuck']])->toBe([1, 1, 1, 1])
        ->and(array_column($lateOrStuck['jobs'], 'id'))->toBe([904, 903])
        ->and($text['code'])->toBe(0)
        ->and($text['out'])->toContain('Last run')
        ->and($text['out'])->toContain('Zeta stuck')
        ->and($text['out'])->not->toContain('Alpha late')
        ->and($text['out'])->toContain('Listed: 1. In the instance: 1 failed, 1 late, 1 stuck.');
});

it('lists no scheduled job on an empty table', function (): void {
    $result = serverJson(['cron', 'list']);
    $text = server(['cron', 'list', '--format=text']);

    expect($result['code'])->toBe(0)
        ->and($result['json']['data'])->toBe(['jobs' => [], 'count' => 0, 'failed' => 0, 'late' => 0, 'stuck' => 0])
        ->and($text['out'])->toBe("No scheduled job.\n");
});
