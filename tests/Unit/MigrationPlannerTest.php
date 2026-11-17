<?php

declare(strict_types=1);

use App\Util\MigrationPlanner;

$files = ['16.0.0-17.0.0.sql', '17.0.0-18.0.0.sql', '18.0.0-19.0.0.sql', '19.0.0-20.0.0.sql', '20.0.0-21.0.0.sql', 'repair.sql'];

it('chains one step per major version', function () use ($files): void {
    expect(MigrationPlanner::plan($files, '17.0.4', '20.0.2'))->toBe([
        ['from' => '17.0.0', 'to' => '18.0.0'],
        ['from' => '18.0.0', 'to' => '19.0.0'],
        ['from' => '19.0.0', 'to' => '20.0.0'],
    ]);
});

it('reruns the branch step for a patch upgrade', function () use ($files): void {
    expect(MigrationPlanner::plan($files, '20.0.1', '20.0.4'))->toBe([
        ['from' => '19.0.0', 'to' => '20.0.0'],
    ]);
});

it('has nothing to do when the database is up to date', function () use ($files): void {
    expect(MigrationPlanner::plan($files, '20.0.4', '20.0.4'))->toBe([]);
});

it('reruns the branch step when forced', function () use ($files): void {
    expect(MigrationPlanner::plan($files, '20.0.4', '20.0.4', true))->toBe([
        ['from' => '19.0.0', 'to' => '20.0.0'],
    ]);
});

it('ignores version suffixes', function () use ($files): void {
    expect(MigrationPlanner::plan($files, '18.0.0', '19.0.0-beta'))->toBe([
        ['from' => '18.0.0', 'to' => '19.0.0'],
    ]);
});

it('compares branches on major and minor numbers', function (): void {
    expect(MigrationPlanner::sameBranch('18.0.0', '18.0.9'))->toBeTrue()
        ->and(MigrationPlanner::sameBranch('18.0.0', '18.01.0'))->toBeFalse()
        ->and(MigrationPlanner::sameBranch('2.1.0', '21.0.0'))->toBeFalse();
});
