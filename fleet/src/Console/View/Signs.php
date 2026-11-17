<?php

declare(strict_types=1);

namespace Dolifleet\Console\View;

use Dolifleet\Console\Data\Row;
use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Terminal\Line;

/**
 * Sign, word and style of a state. The sign and the word always come with the color, so that
 * the state reads the same in black and white, with NO_COLOR or for a color blind operator.
 */
final class Signs
{
    /** @var array<string, array{0: string, 1: string}> state => sign, style */
    private const SIGNS = [
        'critical' => ['!!', 'crit'],
        'failed' => ['!!', 'crit'],
        'warning' => ['! ', 'warn'],
        'unknown' => ['? ', 'unknown'],
        'never' => ['? ', 'unknown'],
        'unreachable' => ['? ', 'unknown'],
        'paused' => ['! ', 'warn'],
        'running' => ['..', 'running'],
        'pending' => ['  ', 'dim'],
        'skipped' => ['  ', 'dim'],
        'ok' => ['  ', ''],
        'done' => ['  ', ''],
    ];

    public static function style(string $state): string
    {
        return self::SIGNS[$state][1] ?? 'unknown';
    }

    public static function sign(string $state): string
    {
        return self::SIGNS[$state][0] ?? '? ';
    }

    /**
     * "!! CRIT." in the state column of a row.
     */
    public static function state(Translator $t, string $state): Line
    {
        return Line::of(self::sign($state).' '.$t->t('state.short.'.(isset(self::SIGNS[$state]) ? $state : 'unknown')), self::style($state));
    }

    /**
     * State of a row of the fleet, refreshing shown as running.
     */
    public static function rowState(Translator $t, Row $row): Line
    {
        if ($row->refreshing) {
            return self::state($t, 'running');
        }
        if ('' !== $row->unreachable) {
            return self::state($t, 'unreachable');
        }

        return self::state($t, $row->status);
    }

    /**
     * [ok], [..], [!!] in front of a step.
     */
    public static function step(string $state): Line
    {
        return match ($state) {
            'done' => Line::of('[ok]'),
            'running' => Line::of('[..]', 'running'),
            'failed' => Line::of('[!!]', 'crit'),
            'paused' => Line::of('[! ]', 'warn'),
            'skipped' => Line::of('[--]', 'dim'),
            default => Line::of('[  ]', 'dim'),
        };
    }
}
