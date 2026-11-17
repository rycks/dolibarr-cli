<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Table;

/**
 * Backstage: every command the console ran for the operator, newest first, with its duration
 * and result. Enter shows the full command line, the exit code and the output.
 */
final class BackstageScreen extends Screen
{
    private int $cursor = 0;

    private int $offset = 0;

    public function breadcrumb(App $app): array
    {
        return [$app->t->t('backstage.title')];
    }

    public function counters(App $app): string
    {
        return $app->t->t('backstage.count', count($app->backend->all()));
    }

    public function keys(App $app): array
    {
        return [[$app->t->t('key.enter'), $app->t->t('key.detail')], [$app->t->t('key.esc'), $app->t->t('key.back')]];
    }

    public function handle(App $app, string $key): bool
    {
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
        if ([] === $tasks) {
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
        $this->cursor = min($this->cursor, count($tasks) - 1);
        $this->offset = Table::scroll($this->cursor, $this->offset, $height - 2, count($tasks));
        $lines = $table->render($width, $height - 1, $this->cursor, $this->offset);
        $lines[] = Line::of('  '.$t->t('backstage.hint'), 'dim');

        return $lines;
    }

    /**
     * @return list<Task>
     */
    private function tasks(App $app): array
    {
        return array_reverse($app->backend->all());
    }
}
