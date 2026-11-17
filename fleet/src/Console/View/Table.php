<?php

declare(strict_types=1);

namespace Dolifleet\Console\View;

use Dolifleet\Console\Terminal\Line;

/**
 * A list with columns, the same everywhere in the console. Columns have a priority: when the
 * width runs short, the least useful ones disappear first; priority 0 never disappears. The
 * flexible column takes the width left. The current row is reversed to the right edge.
 */
final class Table
{
    /** @var list<array{key: string, header: string, min: int, max: int, priority: int, align: string, flex: bool}> */
    private array $columns = [];

    /** @var list<array<string, Line>> */
    private array $rows = [];

    /** @var list<string> */
    private array $styles = [];

    public function column(string $key, string $header, int $min = 3, int $max = 40, int $priority = 1, string $align = 'left', bool $flex = false): self
    {
        $this->columns[] = ['key' => $key, 'header' => $header, 'min' => $min, 'max' => $max, 'priority' => $priority, 'align' => $align, 'flex' => $flex];

        return $this;
    }

    /**
     * @param array<string, Line|string> $cells
     */
    public function row(array $cells, string $style = ''): self
    {
        $row = [];
        foreach ($cells as $key => $cell) {
            $row[$key] = $cell instanceof Line ? $cell : Line::of($cell);
        }
        $this->rows[] = $row;
        $this->styles[] = $style;

        return $this;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * First row shown so that $cursor stays visible in $height rows.
     */
    public static function scroll(int $cursor, int $offset, int $height, int $count): int
    {
        $height = max(1, $height);
        if ($cursor < $offset) {
            $offset = $cursor;
        } elseif ($cursor >= $offset + $height) {
            $offset = $cursor - $height + 1;
        }

        return max(0, min($offset, max(0, $count - $height)));
    }

    /**
     * Header line then the rows from $offset, at most $height lines in all.
     *
     * @return list<Line>
     */
    public function render(int $width, int $height, int $cursor = -1, int $offset = 0): array
    {
        $columns = $this->layout($width);
        $lines = [$this->line($columns, null, 'bold')];
        $visible = array_slice($this->rows, $offset, max(0, $height - 1), true);
        foreach ($visible as $index => $row) {
            $style = $this->styles[$index];
            $line = $this->line($columns, $row, $style);
            $lines[] = $index === $cursor ? $line->fit($width)->styled('selected') : $line;
        }

        return $lines;
    }

    /**
     * @return list<array{key: string, width: int, align: string}>
     */
    private function layout(int $width): array
    {
        $columns = [];
        foreach ($this->columns as $column) {
            $content = Line::widthOf($column['header']);
            foreach ($this->rows as $row) {
                if (isset($row[$column['key']])) {
                    $content = max($content, $row[$column['key']]->width());
                }
            }
            $column['width'] = max($column['min'], min($column['max'], $content));
            $columns[] = $column;
        }
        $total = static fn (array $columns): int => array_sum(array_column($columns, 'width')) + max(0, count($columns) - 1) * 2;
        while ($total($columns) > $width) {
            $drop = null;
            foreach ($columns as $index => $column) {
                if ($column['priority'] > 0 && (null === $drop || $column['priority'] >= $columns[$drop]['priority'])) {
                    $drop = $index;
                }
            }
            if (null === $drop) {
                break;
            }
            array_splice($columns, $drop, 1);
        }
        $left = $width - $total($columns);
        foreach ($columns as $index => $column) {
            if ($column['flex']) {
                $columns[$index]['width'] = max(1, $column['width'] + $left);
                break;
            }
        }

        return array_map(static fn (array $c): array => ['key' => $c['key'], 'width' => $c['width'], 'align' => $c['align'], 'header' => $c['header']], $columns);
    }

    /**
     * @param list<array{key: string, width: int, align: string, header?: string}> $columns
     * @param array<string, Line>|null $row null for the header
     */
    private function line(array $columns, ?array $row, string $style): Line
    {
        $line = Line::blank();
        foreach ($columns as $index => $column) {
            if ($index > 0) {
                $line = $line->add('  ', $style);
            }
            $cell = null === $row ? Line::of((string) ($column['header'] ?? ''), 'bold') : ($row[$column['key']] ?? Line::blank());
            if ('right' === $column['align'] && $cell->width() < $column['width']) {
                $cell = Line::of(str_repeat(' ', $column['width'] - $cell->width()), $style)->append($cell);
            }
            $line = $line->append(('' === $style ? $cell : $cell->styled($style))->fit($column['width'], $style));
        }

        return $line;
    }
}
