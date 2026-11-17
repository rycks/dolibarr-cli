<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Terminal\Line;

/**
 * First start, empty inventory: one sentence on what the tool does, one key to go on, and the
 * command line for those who prefer typing.
 */
final class WelcomeScreen extends Screen
{
    public function breadcrumb(App $app): array
    {
        return [];
    }

    public function counters(App $app): string
    {
        return $app->t->t('welcome.counters');
    }

    public function keys(App $app): array
    {
        return [['a', $app->t->t('key.add')]];
    }

    public function handle(App $app, string $key): bool
    {
        if ('a' === $key || 'enter' === $key) {
            $app->push(new AddScreen());

            return true;
        }

        return false;
    }

    public function body(App $app, int $width, int $height): array
    {
        $t = $app->t;

        return [
            Line::blank(),
            Line::of('   '.$t->t('welcome.line1'), 'bold'),
            Line::blank(),
            Line::of('   '.Line::pad($t->t('welcome.add'), 58))->add('a', 'key'),
            Line::blank(),
            Line::of('   '.$t->t('welcome.cli')),
            Line::of('     dolifleet add <alias-ssh>', 'command'),
            Line::blank(),
            Line::of('   '.$t->t('welcome.help'), 'dim'),
        ];
    }
}
