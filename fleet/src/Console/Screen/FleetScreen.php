<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\Actions;
use Dolifleet\Console\App;
use Dolifleet\Console\Data\Row;
use Dolifleet\Console\Tasks\Command;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Input;
use Dolifleet\Console\View\Signs;
use Dolifleet\Console\View\Table;

/**
 * The fleet: what needs attention first, every instance with its state, checkboxes for batches.
 * Drawn from the local state at once, refreshed line by line in the background.
 */
final class FleetScreen extends Screen implements TargetAware
{
    private const GROUPS = ['none', 'tag', 'server'];
    private const SORTS = ['gravity', 'name', 'version'];

    private int $cursor = 0;

    private int $offset = 0;

    private string $filter = '';

    private ?Input $typing = null;

    /** @var array<string, bool> */
    private array $checked = [];

    private string $group = 'none';

    private string $sort = 'gravity';

    private bool $expanded = false;

    public function breadcrumb(App $app): array
    {
        return ['' === $this->filter ? $app->t->t('fleet.all') : $this->filter];
    }

    public function command(App $app): array
    {
        return Command::status($this->selector($app));
    }

    public function counters(App $app): string
    {
        $rows = $this->visible($app);
        $text = $app->t->t('fleet.count', count($rows));
        if ([] !== $this->checked) {
            $text .= '   '.$app->t->t('fleet.checked', count($this->checked));
        }
        $ages = array_filter(array_map(static fn (Row $r): ?int => $r->age, $rows), static fn (?int $a): bool => null !== $a);
        if ([] !== $ages) {
            $text .= '   '.$app->t->t('fleet.state_age', $app->t->ago(min($ages)));
        }

        return $text;
    }

    public function target(App $app): string
    {
        return implode(',', $this->selection($app));
    }

    public function captures(): bool
    {
        return null !== $this->typing;
    }

    public function keys(App $app): array
    {
        if (null !== $this->typing) {
            return [[$app->t->t('key.enter'), $app->t->t('key.filter_apply')], [$app->t->t('key.esc'), $app->t->t('key.filter_clear')]];
        }
        $count = count($this->selection($app));
        $suffix = $count > 1 ? ' ('.$count.')' : '';
        $keys = [];
        $current = $this->current($app);
        if (null !== $current && [] !== $current->problems && in_array($current->problems[0]->fix, ['b', 'm', 'u'], true)) {
            $keys[] = [$current->problems[0]->fix, $app->t->t('fix.'.$current->problems[0]->fix)];
        }
        foreach ([
            [$app->t->t('key.enter'), $app->t->t('key.card')],
            [$app->t->t('key.space'), $app->t->t('key.check')],
            ['m', $app->t->t('fix.m').$suffix],
            ['b', $app->t->t('fix.b').$suffix],
            ['u', $app->t->t('fix.u')],
            ['/', $app->t->t('key.filter')],
            ['g', $app->t->t('key.group')],
            ['s', $app->t->t('key.sort')],
            ['r', $app->t->t('key.refresh')],
            ['a', $app->t->t('key.add')],
        ] as $pair) {
            if ( ! in_array($pair[0], array_column($keys, 0), true)) {
                $keys[] = $pair;
            }
        }

        return $keys;
    }

    public function help(App $app): array
    {
        return [
            $app->t->t('help.fleet.sort'),
            $app->t->t('help.fleet.filter'),
            $app->t->t('help.fleet.check'),
            $app->t->t('help.fleet.columns'),
        ];
    }

    public function handle(App $app, string $key): bool
    {
        if (null !== $this->typing) {
            return $this->typeFilter($app, $key);
        }
        $entries = $this->entries($app, 1000);
        $count = count($entries);
        switch ($key) {
            case 'up':
                $this->move($entries, -1);

                return true;
            case 'down':
                $this->move($entries, 1);

                return true;
            case 'pgup':
                $this->cursor = max(0, $this->cursor - 10);

                return true;
            case 'pgdn':
                $this->cursor = max(0, min($count - 1, $this->cursor + 10));

                return true;
            case 'home':
                $this->cursor = 0;

                return true;
            case 'end':
                $this->cursor = max(0, $count - 1);

                return true;
            case 'enter':
                $entry = $entries[$this->cursor] ?? null;
                if (is_array($entry) && 'more' === $entry['type']) {
                    $this->expanded = true;
                } elseif (is_array($entry) && 'row' === $entry['type']) {
                    $app->push(new InstanceScreen($entry['row']->id()));
                }

                return true;
            case 'space':
                $row = $this->current($app);
                if (null !== $row) {
                    if (isset($this->checked[$row->id()])) {
                        unset($this->checked[$row->id()]);
                    } else {
                        $this->checked[$row->id()] = true;
                    }
                    $this->move($entries, 1);
                }

                return true;
            case '*':
                $visible = array_map(static fn (Row $r): string => $r->id(), $this->visible($app));
                $all = [] !== $visible && [] === array_diff($visible, array_keys($this->checked));
                $this->checked = [];
                if ( ! $all) {
                    foreach ($visible as $id) {
                        $this->checked[$id] = true;
                    }
                }

                return true;
            case '/':
                $this->typing = new Input($this->filter);

                return true;
            case 'g':
                $this->group = self::GROUPS[(array_search($this->group, self::GROUPS, true) + 1) % count(self::GROUPS)];
                $this->cursor = 0;

                return true;
            case 's':
                $this->sort = self::SORTS[(array_search($this->sort, self::SORTS, true) + 1) % count(self::SORTS)];
                $app->toast($app->t->t('fleet.sorted', $app->t->t('sort.'.$this->sort)));

                return true;
            case 'r':
                $app->refresh(array_map(static fn (Row $r): string => $r->id(), $this->visible($app)));

                return true;
            case 'a':
                $app->push(new AddScreen());

                return true;
            case 'm':
                $ids = $this->selection($app);
                if ([] !== $ids) {
                    $app->push(Actions::maintenance($app, $ids));
                }

                return true;
            case 'b':
                $ids = $this->selection($app);
                if ([] !== $ids) {
                    $app->push(Actions::backup($app, $ids));
                }

                return true;
            case 'u':
                $ids = $this->selection($app);
                if (1 === count($ids)) {
                    $app->push(Actions::upgrade($app, $ids[0]));
                } elseif ([] !== $ids) {
                    $app->toast($app->t->t('fleet.upgrade_one'), 'warn');
                }

                return true;
            case 'esc':
                if ('' !== $this->filter || [] !== $this->checked) {
                    $this->filter = '';
                    $this->checked = [];
                    $this->cursor = 0;

                    return true;
                }

                return false;
        }

        return false;
    }

    public function body(App $app, int $width, int $height): array
    {
        if (null !== $this->typing) {
            $height -= 2;
        }
        $entries = $this->entries($app, $height - 1);
        $this->cursor = max(0, min($this->cursor, count($entries) - 1));
        $this->offset = Table::scroll($this->cursor, $this->offset, $height - 1, count($entries));

        $table = (new Table())
            ->column('check', '', 3, 3, 0)
            ->column('instance', $app->t->t('col.instance'), 12, 30, 0)
            ->column('version', $app->t->t('col.version'), 7, 10, 3)
            ->column('php', $app->t->t('col.php'), 3, 4, 5)
            ->column('state', $app->t->t('col.state'), 9, $app->t->widest('state.short.unreachable') + 3, 0)
            ->column('backup', $app->t->t('col.backup'), 6, 9, 4)
            ->column('maintenance', $app->t->t('col.maintenance'), 5, 6, 6)
            ->column('problem', $app->t->t('col.problem'), 10, 200, 2, 'left', true);
        foreach ($entries as $entry) {
            if ('row' === $entry['type']) {
                $table->row($this->cells($app, $entry['row']));
            } elseif ('group' === $entry['type']) {
                $table->row(['instance' => Line::of($entry['label'], 'bold')]);
            } else {
                $table->row(['instance' => Line::of($entry['label'], 'dim')]);
            }
        }
        $lines = $table->render($width, $height, $this->cursor, $this->offset);
        if ([] === $entries) {
            $lines[] = Line::of(' '.$app->t->t('fleet.no_match', $this->filter), 'dim');
        }
        if (null !== $this->typing) {
            while (count($lines) < $height) {
                $lines[] = Line::blank();
            }
            $lines[] = Line::blank();
            $lines[] = Line::of(' / ', 'key')->add($this->typing->value, 'input')->add(' ', 'reverse');
        }

        return $lines;
    }

    /**
     * Instances acted on: the checked ones, or the current one.
     *
     * @return list<string>
     */
    public function selection(App $app): array
    {
        if ([] !== $this->checked) {
            $known = array_map(static fn (Row $r): string => $r->id(), $app->rows());

            return array_values(array_intersect($known, array_keys($this->checked)));
        }
        $current = $this->current($app);

        return null === $current ? [] : [$current->id()];
    }

    public function current(App $app): ?Row
    {
        $entry = $this->entries($app, 1000)[$this->cursor] ?? null;

        return is_array($entry) && 'row' === $entry['type'] ? $entry['row'] : null;
    }

    /**
     * Rows of the filter, sorted.
     *
     * @return list<Row>
     */
    public function visible(App $app): array
    {
        $rows = array_values(array_filter($app->rows(), fn (Row $row): bool => $this->matches($row)));
        usort($rows, function (Row $a, Row $b): int {
            return match ($this->sort) {
                'name' => strcmp($a->id(), $b->id()),
                'version' => [version_compare($a->version(), $b->version()), $a->id()] <=> [0, $b->id()],
                default => [$b->gravity(), count($b->problems), $a->id()] <=> [$a->gravity(), count($a->problems), $b->id()],
            };
        });

        return $rows;
    }

    /**
     * Lines of the list: rows, group headers, and "N other ok" when the screen is too short.
     *
     * @return list<array{type: string, row?: Row, label: string}>
     */
    private function entries(App $app, int $room): array
    {
        $rows = $this->visible($app);
        $entries = [];
        if ('none' === $this->group) {
            foreach ($rows as $row) {
                $entries[] = ['type' => 'row', 'row' => $row, 'label' => ''];
            }
        } else {
            $groups = [];
            foreach ($rows as $row) {
                $keys = 'server' === $this->group ? [$row->instance->server] : ([] === $row->instance->tags ? ['-'] : $row->instance->tags);
                foreach ($keys as $key) {
                    $groups[$key][] = $row;
                }
            }
            ksort($groups);
            foreach ($groups as $key => $members) {
                $worst = max(array_map(static fn (Row $r): int => $r->gravity(), $members));
                $entries[] = ['type' => 'group', 'label' => ('server' === $this->group ? 'server:' : 'tag:').$key.'  ('.count($members).')'.($worst >= 3 ? '  !!' : ($worst >= 1 ? '  !' : ''))];
                foreach ($members as $row) {
                    $entries[] = ['type' => 'row', 'row' => $row, 'label' => ''];
                }
            }
        }
        if ($this->expanded || 'none' !== $this->group || count($entries) <= $room) {
            return $entries;
        }
        // The tail of instances without problem folds into one line.
        $kept = [];
        $folded = 0;
        foreach ($entries as $index => $entry) {
            $quiet = 'row' === $entry['type'] && 0 === $entry['row']->gravity() && ! isset($this->checked[$entry['row']->id()]);
            if ($index >= $room - 1 && $quiet) {
                ++$folded;
                continue;
            }
            $kept[] = $entry;
        }
        if ($folded > 0) {
            $kept[] = ['type' => 'more', 'label' => $app->t->t('fleet.more_ok', $folded)];
        }

        return $kept;
    }

    /**
     * @return array<string, Line>
     */
    private function cells(App $app, Row $row): array
    {
        $instance = Line::of($row->id());
        if ($row->prod) {
            $instance = $instance->add(' ')->add('PROD', 'prod');
        }
        $problem = Line::blank();
        if ('' !== $row->unreachable) {
            $problem = Line::of($app->t->t('fleet.unreachable', $row->unreachable), 'unknown');
        } elseif ('never' === $row->status && ! $row->refreshing) {
            $problem = Line::of($app->t->t('fleet.never'), 'unknown');
        } elseif ([] !== $row->problems) {
            $first = $row->problems[0];
            $problem = Line::of($first->text, Signs::style($first->status));
            if (count($row->problems) > 1) {
                $problem = $problem->add(' (+'.(count($row->problems) - 1).')', 'dim');
            }
        }
        $maintenance = $row->maintenance();
        $backup = $row->backupAge();

        return [
            'check' => Line::of(isset($this->checked[$row->id()]) ? '[x]' : '[ ]'),
            'instance' => $instance,
            'version' => Line::of($row->version()),
            'php' => Line::of($row->php()),
            'state' => Signs::rowState($app->t, $row),
            'backup' => Line::of(null === $backup ? '' : $app->t->duration($backup), null !== $backup && $backup > 2 * 86400 ? 'warn' : ''),
            'maintenance' => Line::of(null === $maintenance ? '' : ($maintenance ? 'ON' : 'off'), true === $maintenance ? 'warn' : 'dim'),
            'problem' => $problem,
        ];
    }

    private function matches(Row $row): bool
    {
        $filter = trim($this->filter);
        if ('' === $filter) {
            return true;
        }
        if (str_starts_with($filter, 'tag:')) {
            return $row->instance->hasTag(mb_substr($filter, 4));
        }
        if (str_starts_with($filter, 'server:')) {
            return $row->instance->server === mb_substr($filter, 7);
        }
        $needle = mb_strtolower($filter);
        foreach (array_merge([$row->id(), $row->instance->server], $row->instance->tags) as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Target of the pilot matching the filter: the tag or the server typed, all otherwise.
     */
    private function selector(App $app): string
    {
        $filter = trim($this->filter);
        if ('' === $filter) {
            return 'all';
        }
        if (str_starts_with($filter, 'tag:') || str_starts_with($filter, 'server:')) {
            return $filter;
        }
        foreach ($app->rows() as $row) {
            if ($row->instance->hasTag($filter)) {
                $ids = array_map(static fn (Row $r): string => $r->id(), $this->visible($app));
                $tagged = array_map(static fn (Row $r): string => $r->id(), array_filter($app->rows(), static fn (Row $r): bool => $r->instance->hasTag($filter)));
                if ([] === array_diff($ids, $tagged)) {
                    return 'tag:'.$filter;
                }
            }
        }
        $ids = array_map(static fn (Row $r): string => $r->id(), $this->visible($app));

        return [] === $ids ? 'all' : implode(',', $ids);
    }

    private function typeFilter(App $app, string $key): bool
    {
        if (null === $this->typing) {
            return false;
        }
        if ('enter' === $key) {
            $this->typing = null;

            return true;
        }
        if ('esc' === $key) {
            $this->typing = null;
            $this->filter = '';
            $this->cursor = 0;

            return true;
        }
        if ($this->typing->handle($key)) {
            $this->filter = $this->typing->value;
            $this->cursor = 0;
            $this->offset = 0;
        }

        return true;
    }

    /**
     * @param list<array{type: string, row?: Row, label: string}> $entries
     */
    private function move(array $entries, int $step): void
    {
        $count = count($entries);
        $cursor = $this->cursor;
        do {
            $cursor += $step;
        } while ($cursor >= 0 && $cursor < $count && 'group' === $entries[$cursor]['type']);
        if ($cursor >= 0 && $cursor < $count) {
            $this->cursor = $cursor;
        }
    }
}
