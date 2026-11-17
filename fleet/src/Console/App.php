<?php

declare(strict_types=1);

namespace Dolifleet\Console;

use Dolifleet\Console\Data\Checks;
use Dolifleet\Console\Data\Row;
use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Screen\BackstageScreen;
use Dolifleet\Console\Screen\HelpScreen;
use Dolifleet\Console\Screen\OperationsScreen;
use Dolifleet\Console\Screen\PaletteScreen;
use Dolifleet\Console\Screen\Screen;
use Dolifleet\Console\Screen\TargetAware;
use Dolifleet\Console\Tasks\Backend;
use Dolifleet\Console\Tasks\Command;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\Terminal\Style;
use Dolifleet\Inventory\Inventory;
use Dolifleet\Remote\Body;
use Dolifleet\Store\StateStore;
use RuntimeException;

/**
 * State of the console and its layout. Pure: keys in, frames out; the terminal and the
 * processes are behind Console and Backend, so every screen can be tested without either.
 */
final class App
{
    public const MIN_COLS = 80;
    public const MIN_ROWS = 24;

    /** @var list<Screen> */
    private array $screens = [];

    /** @var array{text: string, style: string, until: float}|null */
    private ?array $toast = null;

    /** @var list<Row>|null */
    private ?array $rows = null;

    /** @var array<string, string> instance => error of its last refresh */
    private array $unreachable = [];

    /** @var array<string, bool> */
    private array $refreshing = [];

    /** @var list<string> Instances waiting for a refresh slot */
    private array $refreshQueue = [];

    /** @var list<array{time: float, command: string, result: string}> Writes of the session */
    private array $recap = [];

    private string $output = '';

    private bool $quit = false;

    private bool $redraw = false;

    private ?Inventory $inventory = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public readonly Checks $checks;

    public readonly Operations $operations;

    public function __construct(
        public Translator $t,
        public readonly Style $style,
        public readonly Backend $backend,
        public readonly StateStore $store,
        private readonly string $inventoryFile,
        public Settings $settings,
    ) {
        $this->checks = new Checks($t);
        $this->operations = new Operations($this);
    }

    // ----- inventory and rows

    public function hasInventory(): bool
    {
        return is_file($this->inventoryFile) && [] !== $this->inventory()->instances();
    }

    public function inventory(): Inventory
    {
        if (null === $this->inventory) {
            $this->inventory = is_file($this->inventoryFile) ? Inventory::load($this->inventoryFile) : Inventory::empty();
        }

        return $this->inventory;
    }

    public function reloadInventory(): void
    {
        $this->inventory = null;
        $this->rows = null;
    }

    /**
     * @return list<Row>
     */
    public function rows(): array
    {
        return $this->rows ??= Row::all($this->inventory(), $this->store, $this->checks, $this->unreachable, $this->refreshing);
    }

    public function row(string $id): ?Row
    {
        foreach ($this->rows() as $row) {
            if ($row->id() === $id) {
                return $row;
            }
        }

        return null;
    }

    public function isProd(string $id): bool
    {
        return null !== ($row = $this->row($id)) && $row->prod;
    }

    /**
     * Refresh instances in the background, at most `parallel` at a time, line by line.
     *
     * @param list<string> $ids
     */
    public function refresh(array $ids): void
    {
        foreach ($ids as $id) {
            if ( ! isset($this->refreshing[$id]) && ! in_array($id, $this->refreshQueue, true)) {
                $this->refreshQueue[] = $id;
                $this->refreshing[$id] = true;
            }
        }
        $this->rows = null;
        $this->startRefreshes();
    }

    public function isRefreshing(string $id): bool
    {
        return isset($this->refreshing[$id]);
    }

    // ----- screens

    public function push(Screen $screen): void
    {
        $this->screens[] = $screen;
    }

    public function pop(): void
    {
        if (count($this->screens) > 1) {
            array_pop($this->screens);
        }
    }

    public function replace(Screen $screen): void
    {
        array_pop($this->screens);
        $this->screens[] = $screen;
    }

    public function top(): Screen
    {
        if ([] === $this->screens) {
            throw new RuntimeException('No screen in the console.');
        }

        return $this->screens[count($this->screens) - 1];
    }

    /**
     * @return list<Screen>
     */
    public function screens(): array
    {
        return $this->screens;
    }

    // ----- tasks

    /**
     * @param list<string> $args
     * @param list<string> $instances
     * @param array<string, mixed> $meta
     */
    public function read(array $args, string $purpose, array $instances = [], array $meta = []): Task
    {
        return $this->backend->start($args, $purpose, $instances, false, [], $meta);
    }

    /**
     * A write: detached, journaled by the pilot, listed in the recapitulation of the session.
     *
     * @param list<string> $args
     * @param list<string> $options
     * @param list<string> $instances
     * @param array<string, mixed> $meta
     */
    public function write(array $args, array $options, string $purpose, array $instances, array $meta = []): Task
    {
        $task = $this->backend->start($args, $purpose, $instances, true, $options, $meta);
        $this->recap[] = ['time' => $task->startedAt, 'command' => $task->commandLine(), 'result' => ''];

        return $task;
    }

    /**
     * One round of the loop: finished tasks go to the refresh, the operations and every screen.
     */
    public function tick(): void
    {
        foreach ($this->backend->poll() as $task) {
            if ('refresh' === $task->purpose) {
                $this->refreshed($task);
            }
            if ($task->write && empty($task->meta['resumed'])) {
                foreach ($this->recap as $index => $entry) {
                    if ($entry['command'] === $task->commandLine() && '' === $entry['result']) {
                        $this->recap[$index]['result'] = Task::DONE === $task->state() ? $this->t->t('recap.ok') : $this->t->t('recap.failed', $task->error());
                        break;
                    }
                }
            }
            $this->operations->taskFinished($task);
            foreach ($this->screens as $screen) {
                $screen->taskFinished($this, $task);
            }
        }
        $this->operations->tick();
        $this->startRefreshes();
        if (null !== $this->toast && microtime(true) > $this->toast['until']) {
            $this->toast = null;
        }
    }

    // ----- keys

    public function key(string $key): void
    {
        $screen = $this->top();
        if ($screen->handle($this, $key)) {
            return;
        }
        if ($screen->captures()) {
            return;
        }
        switch ($key) {
            case 'q':
            case 'ctrl-c':
                $this->quit = true;
                break;
            case 'esc':
                $this->pop();
                break;
            case '?':
                $this->push(new HelpScreen($screen));
                break;
            case ':':
                $this->push(new PaletteScreen($this->contextTarget()));
                break;
            case '!':
                if ( ! $screen instanceof BackstageScreen) {
                    $this->push(new BackstageScreen());
                }
                break;
            case 'j':
                if ( ! $screen instanceof OperationsScreen) {
                    $this->push(new OperationsScreen());
                }
                break;
            case 'y':
                $command = $screen->command($this);
                if (null !== $command) {
                    $this->copy(Command::line($command));
                }
                break;
            case 'ctrl-l':
                $this->redraw = true;
                break;
        }
    }

    /**
     * Instances the palette acts on: the instance of the screen, the checked ones, the current one.
     */
    public function contextTarget(): string
    {
        for ($index = count($this->screens) - 1; $index >= 0; --$index) {
            $target = $this->screens[$index] instanceof TargetAware ? $this->screens[$index]->target($this) : '';
            if ('' !== $target) {
                return $target;
            }
        }

        return '';
    }

    /**
     * Copy to the clipboard of the terminal (OSC 52: xterm, kitty, iTerm2, tmux with
     * set-clipboard...), the text also shown in a message for the terminals that ignore it.
     */
    public function copy(string $text): void
    {
        $this->output .= "\e]52;c;".base64_encode($text)."\x07";
        $this->toast($this->t->t('console.copied', $text));
    }

    public function toast(string $text, string $style = '', float $seconds = 5.0): void
    {
        $this->toast = ['text' => $text, 'style' => $style, 'until' => microtime(true) + $seconds];
    }

    public function changeLanguage(Translator $translator, bool $save): void
    {
        $this->t = $translator;
        $this->rows = null;
        if ($save) {
            $this->settings = $this->settings->withLang($translator->code());
            $this->settings->save();
        }
    }

    public function quitRequested(): bool
    {
        return $this->quit;
    }

    public function requestQuit(): void
    {
        $this->quit = true;
    }

    /**
     * Bytes for the terminal besides the frame (clipboard), emptied when read.
     */
    public function takeOutput(): string
    {
        $output = $this->output;
        $this->output = '';

        return $output;
    }

    public function takeRedraw(): bool
    {
        $redraw = $this->redraw;
        $this->redraw = false;

        return $redraw;
    }

    /**
     * @return list<array{time: float, command: string, result: string}>
     */
    public function recap(): array
    {
        return $this->recap;
    }

    /**
     * Manifest of the dolicli commands (palette), read once from the body of the pilot.
     *
     * @return array<string, array<string, mixed>>
     */
    public function manifest(): array
    {
        if (null === $this->manifest) {
            try {
                $this->manifest = Body::locate()->manifest();
            } catch (RuntimeException $e) {
                $this->toast($this->t->t('palette.no_manifest', $e->getMessage()), 'error');
                $this->manifest = [];
            }
        }

        return $this->manifest;
    }

    // ----- layout

    /**
     * The whole screen: five zones, the body of the top screen in the middle.
     *
     * @return list<Line>
     */
    public function frame(int $cols, int $rows): array
    {
        if ($cols < self::MIN_COLS || $rows < self::MIN_ROWS) {
            $lines = array_fill(0, max(0, intdiv($rows, 2) - 1), Line::blank());
            $lines[] = Line::of(' '.$this->t->t('console.too_small', $cols, $rows, self::MIN_COLS, self::MIN_ROWS), 'bold');

            return $lines;
        }
        $screen = $this->top();
        $prod = $screen->prod($this);
        $rule = Line::of($this->style->rule($cols, $prod), $prod ? 'frame-prod' : 'frame');

        $title = Line::of(' dolifleet', 'title');
        foreach ($screen->breadcrumb($this) as $part) {
            $title = $title->add(' > ', 'dim')->add($part, 'title');
        }
        if ($prod) {
            $title = $title->add('  ')->add(' PROD ', 'prod');
        }
        $counters = $screen->counters($this);
        $right = '' === $counters ? '' : $counters.' ';
        $title = $title->fit($cols - Line::widthOf($right))->add($right, 'dim');

        $command = $screen->command($this);
        $commandLine = null === $command ? Line::of(' ') : Line::of(' $ ', 'dim')->add(Command::line($command), 'command');

        $footer = [];
        foreach ($this->operations->band($cols) as $line) {
            $footer[] = $line;
        }
        if (null !== $this->toast) {
            $footer[] = Line::of(' '.$this->toast['text'], '' === $this->toast['style'] ? 'bold' : $this->toast['style']);
        }
        $footer[] = $this->keyBar($screen, $cols);

        $height = $rows - 3 - 1 - count($footer);
        $body = array_slice($screen->body($this, $cols, $height), 0, $height);
        while (count($body) < $height) {
            $body[] = Line::blank();
        }

        return array_merge([$title, $commandLine, $rule], $body, [$rule], $footer);
    }

    private function keyBar(Screen $screen, int $cols): Line
    {
        $pairs = $screen->keys($this);
        if ( ! $screen->captures()) {
            $pairs[] = ['?', $this->t->t('key.help')];
            $pairs[] = ['q', $this->t->t('key.quit')];
        }
        $line = Line::blank();
        foreach ($pairs as [$key, $label]) {
            $piece = Line::of(' '.$key, 'key')->add(' '.$label.' ');
            if ($line->width() + $piece->width() > $cols - 1) {
                break;
            }
            $line = $line->append($piece);
        }

        return $line;
    }

    private function refreshed(Task $task): void
    {
        foreach ($task->instances as $id) {
            unset($this->refreshing[$id]);
            $outcome = $task->json['instances'][$id] ?? null;
            if (is_array($outcome) && ! empty($outcome['ok'])) {
                unset($this->unreachable[$id]);
            } else {
                $this->unreachable[$id] = is_array($outcome) && '' !== (string) ($outcome['error'] ?? '') ? (string) $outcome['error'] : $task->error();
            }
        }
        $this->rows = null;
    }

    private function startRefreshes(): void
    {
        $parallel = max(1, (int) ($this->inventory()->default('parallel') ?? 4));
        while ([] !== $this->refreshQueue && $this->backend->runningReads() < $parallel) {
            $id = array_shift($this->refreshQueue);
            $this->read(Command::status($id), 'refresh', [$id]);
        }
    }
}
