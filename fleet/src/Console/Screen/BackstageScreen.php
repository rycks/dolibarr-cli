<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\ListControls;
use Dolifleet\Console\View\Table;

/**
 * Backstage: every command the console ran for the operator, newest first, with its duration
 * and result. Enter shows the full command line, the exit code and the output.
 */
final class BackstageScreen extends Screen
{
    private int $cursor = 0;

    private int $offset = 0;

    private ListControls $controls;

    public function __construct()
    {
        $this->controls = new ListControls(['time' => 'col.time', 'command' => 'col.command', 'duration' => 'col.duration', 'state' => 'col.state']);
    }

    public function captures(): bool
    {
        return $this->controls->captures();
    }

    public function breadcrumb(App $app): array
    {
        return [$app->t->t('backstage.title')];
    }

    public function counters(App $app): string
    {
        return trim($this->controls->summary($app->t).'   '.$app->t->t('backstage.count', count($app->backend->all())));
    }

    public function keys(App $app): array
    {
        if ($this->controls->captures()) {
            return $this->controls->keys($app->t);
        }

        return array_merge([[$app->t->t('key.enter'), $app->t->t('key.detail')]], $this->controls->keys($app->t), [[$app->t->t('key.esc'), $app->t->t('key.back')]]);
    }

    public function handle(App $app, string $key): bool
    {
        if (($this->controls->captures() || in_array($key, ['/', 's', 'S', 'esc'], true)) && $this->controls->handle($key)) {
            $this->cursor = 0;
            $this->offset = 0;

            return true;
        }
        $tasks = $this->tasks($app);
        $count = count($tasks);
        switch ($key) {
            case 'up':
                $this->cursor = max(0, $this->cursor - 1);

                return true;
            case 'down':
                $this->cursor = min(max(0, $count - 1), $this->cursor + 1);

                return true;
            case 'pgup':
                $this->cursor = max(0, $this->cursor - 10);

                return true;
            case 'pgdn':
                $this->cursor = min(max(0, $count - 1), $this->cursor + 10);

                return true;
            case 'enter':
                $task = $tasks[$this->cursor] ?? null;
                if (null !== $task) {
                    $app->push(new DetailScreen([$app->t->t('backstage.title'), $task->id], [
                        $app->t->t('backstage.command') => $task->commandLine(),
                        $app->t->t('backstage.purpose') => $task->purpose,
                        $app->t->t('backstage.instances') => implode(', ', $task->instances),
                        $app->t->t('backstage.state') => $app->t->t('task.'.$task->state()).(null === $task->exitCode ? '' : ' (exit '.$task->exitCode.')'),
                        $app->t->t('backstage.duration') => number_format($task->duration(), 1).' s',
                        $app->t->t('backstage.error') => $task->error(),
                        'stderr' => implode("\n", $task->stderrTail(40)),
                        'stdout' => mb_substr($task->stdout, 0, 20000),
                    ], $task->args));
                }

                return true;
        }

        return false;
    }

    public function body(App $app, int $width, int $height): array
    {
        $t = $app->t;
        $tasks = $this->tasks($app);
        if ([] === $app->backend->all()) {
            return [Line::of('  '.$t->t('backstage.empty'), 'dim')];
        }
        $table = (new Table())
            ->column('time', $t->t('col.time'), 8, 8, 0)
            ->column('command', $t->t('col.command'), 20, 200, 0, 'left', true)
            ->column('duration', $t->t('col.duration'), 6, 8, 2, 'right')
            ->column('state', $t->t('col.state'), 6, 10, 0);
        foreach ($tasks as $task) {
            $state = $task->state();
            $table->row([
                'time' => date('H:i:s', (int) $task->startedAt),
                'command' => Line::of($task->commandLine(), $task->write ? 'bold' : ''),
                'duration' => number_format($task->duration(), 1).' s',
                'state' => Line::of($t->t('task.'.$state), Task::FAILED === $state ? 'crit' : (Task::RUNNING === $state ? 'running' : '')),
            ]);
        }
        $input = $this->controls->inputLine();
        $room = $height - 1 - (null === $input ? 0 : 2);
        $this->cursor = max(0, min($this->cursor, count($tasks) - 1));
        $this->offset = Table::scroll($this->cursor, $this->offset, $room - 1, count($tasks));
        $lines = $table->render($width, $room, $this->cursor, $this->offset);
        while (count($lines) < $room) {
            $lines[] = Line::blank();
        }
        $lines[] = Line::of('  '.$t->t('backstage.hint'), 'dim');
        if (null !== $input) {
            $lines[] = Line::blank();
            $lines[] = $input;
        }

        return $lines;
    }

    /**
     * Tasks shown, newest first, through the filter and the sort.
     *
     * @return list<Task>
     */
    private function tasks(App $app): array
    {
        $byId = [];
        $records = [];
        foreach (array_reverse($app->backend->all()) as $task) {
            $byId[$task->id] = $task;
            $records[] = ['_id' => $task->id, 'time' => date('H:i:s', (int) $task->startedAt), 'command' => $task->commandLine(), 'duration' => round($task->duration(), 1), 'state' => $app->t->t('task.'.$task->state())];
        }

        return array_map(static fn (array $record): Task => $byId[(string) $record['_id']], $this->controls->apply($records));
    }
}
