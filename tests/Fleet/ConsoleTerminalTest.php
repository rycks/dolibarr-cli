<?php

declare(strict_types=1);

use Dolifleet\Console\Terminal\Keys;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\Terminal\Renderer;
use Dolifleet\Console\Terminal\Style;

it('decodes keys, sequences and UTF-8 characters, and keeps a lone escape pending', function (): void {
    [$keys, $rest] = Keys::decode("a\e[A\e[6~ \r\x7f\x03é\e[Z\e[1;5C/");
    [$pending, $kept] = Keys::decode("x\e");
    [$escape] = Keys::decode("\e", true);
    [$cut, $partial] = Keys::decode("\e[");
    [$utf8, $half] = Keys::decode("\xC3");

    expect($keys)->toBe(['a', 'up', 'pgdn', 'space', 'enter', 'backspace', 'ctrl-c', 'é', 'shift-tab', '/'])
        ->and($rest)->toBe('')
        ->and($pending)->toBe(['x'])
        ->and($kept)->toBe("\e")
        ->and($escape)->toBe(['esc'])
        ->and($cut)->toBe([])
        ->and($partial)->toBe("\e[")
        ->and($utf8)->toBe([])
        ->and($half)->toBe("\xC3");
});

it('fits lines to a width, counting accented and wide characters by their display width', function (): void {
    $line = Line::of('état ', 'dim')->add('sauvegardé', 'warn');

    expect($line->width())->toBe(15)
        ->and($line->fit(20)->width())->toBe(20)
        ->and($line->fit(10)->text())->toBe('état sa...')
        ->and($line->fit(10)->width())->toBe(10)
        ->and(Line::of('日本語のテキスト')->fit(9)->width())->toBe(9)
        ->and(Line::of("a\e[31mb\x07c")->text())->toBe('a[31mbc')
        ->and(Line::pad('é', 3, true))->toBe('  é');
});

it('rewrites only the rows that changed, and everything after a resize', function (): void {
    $renderer = new Renderer(new Style(false, false));
    $first = $renderer->render([Line::of('one'), Line::of('two'), Line::of('three')], 20, 3);
    $second = $renderer->render([Line::of('one'), Line::of('TWO'), Line::of('three')], 20, 3);
    $resized = $renderer->render([Line::of('one'), Line::of('TWO'), Line::of('three')], 30, 3);

    expect($first)->toContain("\e[2J")
        ->and($first)->toContain("\e[1;1Hone")
        ->and($second)->not->toContain('one')
        ->and($second)->toContain("\e[2;1HTWO")
        ->and($second)->not->toContain('three')
        ->and($resized)->toContain("\e[2J")
        ->and($resized)->toContain('three');
});

it('keeps bold and reverse without colors, and draws rules in ASCII outside UTF-8', function (): void {
    $colors = new Style(true, true);
    $mono = new Style(false, false);

    expect($colors->sgr('crit'))->toBe("\e[1;31m")
        ->and($mono->sgr('crit'))->toBe("\e[1m")
        ->and($mono->sgr('warn'))->toBe('')
        ->and($mono->sgr('selected crit'))->toBe("\e[7;1m")
        ->and($mono->rule(3, true))->toBe('===')
        ->and($mono->rule(3))->toBe('---')
        ->and(mb_strlen($colors->rule(3)))->toBe(3);
});
