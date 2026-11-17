<?php

declare(strict_types=1);

namespace Dolifleet\Console\View;

use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Terminal\Line;

/**
 * Filter (/) and sort (s, S to reverse) of a list, the same gestures on every list of the
 * console. The filter keeps the records where any value contains the text typed.
 */
final class ListControls
{
    private ?Input $typing = null;

    private string $filter = '';

    /** Index in the sortable fields, -1 for the order received. */
    private int $sort = -1;

    private bool $descending = false;

    /** @var list<string> */
    private array $order;

    /**
     * @param array<string, string> $fields Field => key of its column label, in the order s cycles them
     */
    public function __construct(private readonly array $fields)
    {
        $this->order = array_keys($fields);
    }

    public function captures(): bool
    {
        return null !== $this->typing;
    }

    /**
     * True when the key was used (filter typed, sort changed).
     */
    public function handle(string $key): bool
    {
        if (null !== $this->typing) {
            if ('enter' === $key) {
                $this->typing = null;
            } elseif ('esc' === $key) {
                $this->typing = null;
                $this->filter = '';
            } elseif ($this->typing->handle($key)) {
                $this->filter = $this->typing->value;
            }

            return true;
        }

        switch ($key) {
            case '/':
                $this->typing = new Input($this->filter);

                return true;
            case 's':
                if ([] !== $this->order) {
                    $this->sort = $this->sort + 1 >= count($this->order) ? -1 : $this->sort + 1;
                    $this->descending = false;
                }

                return true;
            case 'S':
                $this->descending = ! $this->descending;

                return true;
            case 'esc':
                if ('' !== $this->filter) {
                    $this->filter = '';

                    return true;
                }

                return false;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    public function apply(array $records): array
    {
        $needle = mb_strtolower(trim($this->filter));
        if ('' !== $needle) {
            $records = array_values(array_filter($records, static function (array $record) use ($needle): bool {
                foreach ($record as $key => $value) {
                    // Fields starting with _ are internal (identifiers), never matched.
                    if ( ! str_starts_with((string) $key, '_') && is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }
        if ($this->sort >= 0) {
            $field = $this->order[$this->sort];
            usort($records, static function (array $a, array $b) use ($field): int {
                $left = $a[$field] ?? null;
                $right = $b[$field] ?? null;
                if (is_numeric($left) && is_numeric($right)) {
                    return $left <=> $right;
                }

                return strnatcasecmp(is_scalar($left) ? (string) $left : '', is_scalar($right) ? (string) $right : '');
            });
            if ($this->descending) {
                $records = array_reverse($records);
            }
        } elseif ($this->descending) {
            $records = array_reverse($records);
        }

        return $records;
    }

    /**
     * "filtre : xx   tri : version" for the counters, '' when nothing applies.
     */
    public function summary(Translator $t): string
    {
        $parts = [];
        if ('' !== $this->filter) {
            $parts[] = $t->t('list.filter', $this->filter);
        }
        if ($this->sort >= 0) {
            $parts[] = $t->t($this->descending ? 'list.sort_desc' : 'list.sort', $t->t($this->fields[$this->order[$this->sort]]));
        } elseif ($this->descending) {
            $parts[] = $t->t('list.reversed');
        }

        return implode('   ', $parts);
    }

    /**
     * Line of the filter being typed, shown under the list.
     */
    public function inputLine(): ?Line
    {
        return null === $this->typing ? null : Line::of(' / ', 'key')->add($this->typing->value, 'input')->add(' ', 'reverse');
    }

    /**
     * Keys for the bar.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function keys(Translator $t): array
    {
        if (null !== $this->typing) {
            return [[$t->t('key.enter'), $t->t('key.filter_apply')], [$t->t('key.esc'), $t->t('key.filter_clear')]];
        }

        return [['/', $t->t('key.filter')], ['s', $t->t('key.sort')]];
    }
}
