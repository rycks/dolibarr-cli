<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Signs;
use Dolifleet\Console\View\Table;

/**
 * Result of a read of the palette: a list of records becomes one table, with a column of the
 * instance when there are several; other data is shown field by field, instance by instance.
 */
final class ResultScreen extends Screen
{
    private int $cursor = 0;

    private int $offset = 0;

    /**
     * @param list<string> $args
     */
    public function __construct(private readonly Task $task, private readonly array $args, private readonly string $title)
    {
    }

    public function breadcrumb(App $app): array
    {
        return [$this->title];
    }

    public function command(App $app): array
    {
        return $this->args;
    }

    public function counters(App $app): string
    {
        return $app->t->t('preview.instances', count($this->task->instances));
    }

    public function keys(App $app): array
    {
        return [[$app->t->t('key.enter'), $app->t->t('key.detail')], ['y', $app->t->t('key.copy')], [$app->t->t('key.esc'), $app->t->t('key.back')]];
    }

    public function handle(App $app, string $key): bool
    {
        $records = $this->records();
        switch ($key) {
            case 'up':
                $this->cursor = max(0, $this->cursor - 1);

                return true;
            case 'down':
                $this->cursor = min(max(0, count($records) - 1), $this->cursor + 1);

                return true;
            case 'enter':
                if (isset($records[$this->cursor])) {
                    $app->push(new DetailScreen([$this->title], $records[$this->cursor], $this->args));
                } elseif ( ! $this->task->finished()) {
                    return true;
                } else {
                    $fields = [];
                    foreach ($this->task->instances as $id) {
                        $fields[$id] = $this->task->data($id);
                    }
                    $app->push(new DetailScreen([$this->title], $fields, $this->args));
                }

                return true;
        }

        return false;
    }

    public function body(App $app, int $width, int $height): array
    {
        if ( ! $this->task->finished()) {
            return [Line::of('  ')->append(Signs::step('running'))->add(' '.$app->t->t('instance.loading'), 'running')];
        }
        $lines = [];
        foreach ($this->task->instances as $id) {
            $outcome = $this->task->outcome($id);
            if ([] === $outcome || empty($outcome['ok'])) {
                $lines[] = Line::of('  !! '.$id.': '.(string) ($outcome['error'] ?? $this->task->error()), 'crit');
            }
        }
        $records = $this->records();
        if ([] !== $records) {
            $keys = [];
            foreach ($records as $record) {
                foreach ($record as $key => $value) {
                    if (is_scalar($value) || null === $value) {
                        $keys[$key] = true;
                    }
                }
            }
            $table = new Table();
            $priority = 0;
            foreach (array_slice(array_keys($keys), 0, 8) as $key) {
                $table->column((string) $key, (string) $key, 3, 40, $priority++ < 2 ? 0 : $priority);
            }
            foreach ($records as $record) {
                $cells = [];
                foreach ($record as $key => $value) {
                    if (is_scalar($value) || null === $value) {
                        $cells[(string) $key] = DetailScreen::text($value);
                    }
                }
                $table->row($cells);
            }
            $this->cursor = min($this->cursor, count($records) - 1);
            $this->offset = Table::scroll($this->cursor, $this->offset, $height - count($lines) - 1, count($records));

            return array_merge($lines, $table->render($width, $height - count($lines), $this->cursor, $this->offset));
        }
        foreach ($this->task->instances as $id) {
            if (count($this->task->instances) > 1) {
                $lines[] = Line::of('== '.$id, 'bold');
            }
            foreach ($this->task->data($id) as $key => $value) {
                $lines[] = Line::of('  '.Line::pad((string) $key, 24), 'bold')->add(DetailScreen::text(is_array($value) ? count($value) : $value));
            }
        }

        return $lines;
    }

    /**
     * The first list of records of the data, an "instance" field added when there are several.
     *
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        if ( ! $this->task->finished()) {
            return [];
        }
        $records = [];
        $several = count($this->task->instances) > 1;
        foreach ($this->task->instances as $id) {
            foreach ($this->task->data($id) as $value) {
                if (is_array($value) && array_is_list($value) && [] !== $value && is_array($value[0])) {
                    foreach ($value as $record) {
                        if (is_array($record)) {
                            $records[] = $several ? ['instance' => $id] + $record : $record;
                        }
                    }
                    break;
                }
            }
        }

        return $records;
    }
}
