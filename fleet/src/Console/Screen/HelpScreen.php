<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Command;
use Dolifleet\Console\Terminal\Line;

/**
 * Help of the screen below, not a manual: its keys, its columns and signs, its command, and
 * where to go further.
 */
final class HelpScreen extends Screen
{
    public function __construct(private readonly Screen $of)
    {
    }

    public function breadcrumb(App $app): array
    {
        return array_merge($this->of->breadcrumb($app), [$app->t->t('help.title')]);
    }

    public function command(App $app): ?array
    {
        return $this->of->command($app);
    }

    public function keys(App $app): array
    {
        return [[$app->t->t('key.esc'), $app->t->t('key.back')]];
    }

    public function body(App $app, int $width, int $height): array
    {
        $t = $app->t;
        $lines = [Line::of('  '.$t->t('help.keys_here'), 'bold')];
        foreach ($this->of->keys($app) as [$key, $label]) {
            $lines[] = Line::of('    '.Line::pad($key, 10), 'key')->add($label);
        }
        $lines[] = Line::blank();
        $lines[] = Line::of('  '.$t->t('help.keys_everywhere'), 'bold');
        foreach ([['?', 'key.help'], [':', 'key.palette'], ['/', 'key.filter'], [$t->t('key.esc'), 'key.back'], ['y', 'key.copy'], ['!', 'key.backstage'], ['r', 'key.refresh'], ['j', 'op.follow'], ['q', 'key.quit']] as [$key, $label]) {
            $lines[] = Line::of('    '.Line::pad($key, 10), 'key')->add($t->t($label));
        }
        $lines[] = Line::blank();
        $lines[] = Line::of('  '.$t->t('help.signs'), 'bold');
        $lines[] = Line::of('    !! ', 'crit')->add($t->t('help.sign.critical'));
        $lines[] = Line::of('    !  ', 'warn')->add($t->t('help.sign.warning'));
        $lines[] = Line::of('    ?  ', 'unknown')->add($t->t('help.sign.unknown'));
        $lines[] = Line::of('    ..  ', 'running')->add($t->t('help.sign.running'));
        $lines[] = Line::of('    ')->add('PROD', 'prod')->add(' '.$t->t('help.sign.prod'));
        foreach ($this->of->help($app) as $text) {
            $lines[] = Line::of('  '.$text, 'dim');
        }
        $command = $this->of->command($app);
        if (null !== $command) {
            $lines[] = Line::blank();
            $lines[] = Line::of('  '.$t->t('help.command'), 'bold');
            $lines[] = Line::of('    '.Command::line($command), 'command');
        }
        $lines[] = Line::blank();
        $lines[] = Line::of('  '.$t->t('help.further'), 'dim');

        return array_slice($lines, 0, $height);
    }
}
