<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Operation;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Signs;

/**
 * Operations of the session: the list, and the steps of the selected one. Esc leaves them
 * running: they live on the servers or detached, not in this screen.
 */
final class OperationsScreen extends Screen
{
    private int $cursor = 0;

    public function breadcrumb(App $app): array
    {
        return [$app->t->t('op.title')];
    }

    public function command(App $app): ?array
    {
        $operation = $this->current($app);
        if (null === $operation || [] === $operation->instances) {
            return null;
        }
        $id = $operation->instances[0];

        return 'upgrade' === $operation->kind ? ['upgrade', 'status', $id] : ['jobs', implode(',', $operation->instances)];
    }

    public function keys(App $app): array
    {
        $keys = [];
        $operation = $this->current($app);
        if (null !== $operation && Operation::PAUSED === $operation->state) {
            $keys[] = ['d', $app->t->t('key.step_done')];
        }
        $keys[] = [$app->t->t('key.arrows'), $app->t->t('key.choose')];
        $keys[] = ['!', $app->t->t('key.backstage')];
        $keys[] = [$app->t->t('key.esc'), $app->t->t('key.leave_running')];

        return $keys;
    }

    public function handle(App $app, string $key): bool
    {
        $count = count($app->operations->all());
        switch ($key) {
            case 'up':
                $this->cursor = max(0, $this->cursor - 1);

                return true;
            case 'down':
                $this->cursor = min(max(0, $count - 1), $this->cursor + 1);

                return true;
            case 'd':
                $operation = $this->current($app);
                if (null !== $operation && Operation::PAUSED === $operation->state) {
                    $app->operations->resume($operation);
                    $app->toast($app->t->t('op.resumed', $operation->title));
                }

                return true;
        }

        return false;
    }

    public function body(App $app, int $width, int $height): array
    {
        $t = $app->t;
        $operations = $app->operations->all();
        if ([] === $operations) {
            return [Line::of('  '.$t->t('op.none'), 'dim')];
        }
        $this->cursor = min($this->cursor, count($operations) - 1);
        $lines = [];
        foreach (array_slice($operations, 0, 5) as $index => $operation) {
            $line = Line::of(' '.($index === $this->cursor ? '>' : ' ').' ')
                ->add($t->time((int) $operation->startedAt).'  ', 'dim')
                ->add($operation->title, $index === $this->cursor ? 'bold' : '')
                ->add('  '.$t->t('op.state.'.$operation->state).' '.$operation->progress(), Signs::style($operation->state));
            $lines[] = $line;
        }
        $lines[] = Line::of($app->style->rule($width), 'frame');
        $operation = $operations[$this->cursor];
        $lines[] = Line::of('  '.$operation->title, 'bold')->add('   '.$t->t('op.started_at', $t->time((int) $operation->startedAt)), 'dim');
        foreach ($operation->steps as $index => $step) {
            $lines[] = Line::of('  ')->append(Signs::step($step['state']))
                ->add(' '.($index + 1).' '.$step['label'])
                ->add('' !== $step['detail'] ? '   '.$step['detail'] : '', 'dim');
        }
        if (Operation::PAUSED === $operation->state) {
            $lines[] = Line::blank();
            $step = '' !== $operation->pausedStep && $t->has('upgrade.step.'.$operation->pausedStep) ? $t->t('upgrade.step.'.$operation->pausedStep) : $operation->pausedStep;
            $lines[] = Line::of('  '.$t->t('op.waiting', $step), 'warn');
            $lines[] = Line::of('  '.$t->t('op.waiting_hint'), 'dim');
        }
        if ('' !== $operation->error) {
            $lines[] = Line::blank();
            $lines[] = Line::of('  !! '.$operation->error, 'crit');
        }

        return $lines;
    }

    private function current(App $app): ?Operation
    {
        return $app->operations->all()[$this->cursor] ?? null;
    }
}
