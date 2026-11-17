<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Input;
use Dolifleet\Console\View\Signs;
use Dolifleet\Inventory\Inventory;

/**
 * Assistant to add a server, inside the console, one step per line, each ticked when it works:
 * SSH alias, host key (fingerprint shown before it is accepted), discovery, choice of the
 * instances (keep, rename, production), then dolifleet add. Each step is a dolifleet command.
 */
final class AddScreen extends Screen
{
    private string $step = 'server';

    private Input $input;

    private string $server = '';

    private string $root = '/var/www';

    private string $php = 'php';

    private bool $sudo = false;

    private ?string $waiting = null;

    /** @var list<string> */
    private array $fingerprints = [];

    private string $error = '';

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{found: string, name: string, keep: bool, prod: bool, url: string, version: string, htdocs: string, owner: string}> */
    private array $found = [];

    private int $cursor = 0;

    /** Field being typed: server, root, rename. */
    private string $editing = 'server';

    public function __construct()
    {
        $this->input = new Input('', 120);
    }

    public function breadcrumb(App $app): array
    {
        return [$app->t->t('add.title')];
    }

    public function command(App $app): ?array
    {
        if ('' === $this->server) {
            return null;
        }

        return match ($this->step) {
            'hostkey' => ['hostkey', $this->server],
            'discover' => $this->discoverArgs(),
            default => $this->addArgs($app),
        };
    }

    public function captures(): bool
    {
        return '' !== $this->editing;
    }

    public function keys(App $app): array
    {
        $t = $app->t;
        if ('' !== $this->editing) {
            return [[$t->t('key.enter'), $t->t('key.next')], [$t->t('key.esc'), $t->t('key.cancel')]];
        }
        if (null !== $this->waiting) {
            return [[$t->t('key.esc'), $t->t('key.cancel')]];
        }

        return match ($this->step) {
            'hostkey' => [['o', $t->t('add.accept_key')], [$t->t('key.esc'), $t->t('key.cancel')]],
            'choose' => [
                [$t->t('key.space'), $t->t('add.keep')],
                ['n', $t->t('add.rename')],
                ['p', $t->t('add.prod')],
                [$t->t('key.enter'), $t->t('add.do_add')],
                ['s', $t->t($this->sudo ? 'add.without_sudo' : 'add.with_sudo')],
                ['d', $t->t('add.directory')],
                [$t->t('key.esc'), $t->t('key.cancel')],
            ],
            default => [['s', $t->t($this->sudo ? 'add.without_sudo' : 'add.with_sudo')], ['d', $t->t('add.directory')], [$t->t('key.esc'), $t->t('key.cancel')]],
        };
    }

    public function handle(App $app, string $key): bool
    {
        if ('' !== $this->editing) {
            return $this->type($app, $key);
        }
        if ('esc' === $key) {
            $app->pop();

            return true;
        }
        if (null !== $this->waiting) {
            return true;
        }
        if ('hostkey' === $this->step && ('o' === $key || 'enter' === $key) && [] !== $this->fingerprints) {
            $this->waiting = $app->write(['hostkey', $this->server, '--accept'], [], 'add-hostkey', [])->id;

            return true;
        }
        if ('s' === $key && in_array($this->step, ['choose', 'none'], true)) {
            $this->sudo = ! $this->sudo;
            $this->discover($app);

            return true;
        }
        if ('d' === $key && in_array($this->step, ['choose', 'none'], true)) {
            $this->editing = 'root';
            $this->input = new Input($this->root, 200);

            return true;
        }
        if ('choose' !== $this->step) {
            return true;
        }
        switch ($key) {
            case 'up':
                $this->cursor = max(0, $this->cursor - 1);

                return true;
            case 'down':
                $this->cursor = min(count($this->found) - 1, $this->cursor + 1);

                return true;
            case 'space':
                $this->found[$this->cursor]['keep'] = ! $this->found[$this->cursor]['keep'];

                return true;
            case 'p':
                $this->found[$this->cursor]['prod'] = ! $this->found[$this->cursor]['prod'];

                return true;
            case 'n':
                $this->editing = 'rename';
                $this->input = new Input($this->found[$this->cursor]['name'], 64);

                return true;
            case 'enter':
                if ([] === array_filter($this->found, static fn (array $f): bool => $f['keep'])) {
                    $this->error = $app->t->t('add.nothing_kept');

                    return true;
                }
                $this->error = '';
                $this->step = 'adding';
                $this->waiting = $app->write($this->addArgs($app), [], 'add', [])->id;

                return true;
        }

        return true;
    }

    public function taskFinished(App $app, Task $task): void
    {
        if ($task->id !== $this->waiting) {
            return;
        }
        $this->waiting = null;
        switch ($task->purpose) {
            case 'add-hostkey-probe':
            case 'add-hostkey':
                $this->hostKeyDone($app, $task);
                break;
            case 'add-discover':
                $this->discovered($app, $task);
                break;
            case 'add':
                $this->added($app, $task);
                break;
        }
    }

    public function body(App $app, int $width, int $height): array
    {
        $t = $app->t;
        $lines = [];
        if ('server' === $this->editing) {
            $lines[] = Line::of('   '.Line::pad($t->t('add.server'), 14), 'bold')->add($this->input->value, 'input')->add(' ', 'reverse');
            $lines[] = Line::of('   '.Line::pad('', 14).$t->t('add.server_hint'), 'dim');

            return $lines;
        }
        $lines[] = Line::of('   '.Line::pad($t->t('add.server'), 14), 'bold')->add($this->server);
        $lines[] = $this->stepLine($t->t('add.step.hostkey'), $this->state('hostkey'), [] !== $this->fingerprints && 'hostkey' === $this->step ? '' : ($this->stepDone('hostkey') ? $t->t('add.key_known') : ''));
        if ('hostkey' === $this->step && [] !== $this->fingerprints) {
            foreach ($this->fingerprints as $fingerprint) {
                $lines[] = Line::of('         '.$fingerprint, 'command');
            }
            $lines[] = Line::of('         '.$t->t('add.check_fingerprint'), 'warn');
        }
        if ($this->stepDone('hostkey')) {
            $lines[] = $this->stepLine($t->t('add.step.search'), $this->state('discover'), $this->root.($this->sudo ? ' (sudo)' : ''));
        }
        foreach ($this->warnings as $warning) {
            $lines[] = Line::of('         ! '.$warning, 'warn');
        }
        if ('none' === $this->step) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   '.$t->t('add.none', $this->root), 'warn');
        }
        if (in_array($this->step, ['choose', 'adding'], true)) {
            $lines[] = $this->stepLine($t->t('add.step.found', count($this->found)), 'adding' === $this->step && null !== $this->waiting ? 'running' : 'done', '');
            $lines[] = Line::blank();
            foreach ($this->found as $index => $found) {
                $line = Line::of('     '.($found['keep'] ? '[x] ' : '[ ] '))
                    ->add(Line::pad('' !== $found['url'] ? $found['url'] : $found['htdocs'], 34).' ')
                    ->add(Line::pad($found['version'], 8).' ')
                    ->add($t->t('add.name').' ', 'dim')->add(Line::pad($found['name'], 22), 'bold')
                    ->add($found['prod'] ? ' [x] PROD' : ' [ ] PROD', $found['prod'] ? 'prod' : 'dim');
                $lines[] = $index === $this->cursor && 'choose' === $this->step ? $line->fit($width)->styled('selected') : $line;
            }
            if ('rename' === $this->editing) {
                $lines[] = Line::blank();
                $lines[] = Line::of('   '.$t->t('add.new_name').' ', 'bold')->add($this->input->value, 'input')->add(' ', 'reverse');
            }
        }
        if ('root' === $this->editing) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   '.$t->t('add.directory').' : ', 'bold')->add($this->input->value, 'input')->add(' ', 'reverse');
        }
        if ('' !== $this->error) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   !! '.$this->error, 'error');
        }

        return $lines;
    }

    private function type(App $app, string $key): bool
    {
        if ('esc' === $key) {
            if ('server' === $this->editing) {
                $app->pop();
            }
            $this->editing = '';

            return true;
        }
        if ('enter' !== $key) {
            $this->input->handle($key);

            return true;
        }
        $value = trim($this->input->value);
        $field = $this->editing;
        $this->editing = '';
        $this->error = '';
        if ('server' === $field) {
            if (1 !== preg_match(Inventory::SERVER_PATTERN, $value)) {
                $this->error = $app->t->t('add.bad_server');
                $this->editing = 'server';

                return true;
            }
            $this->server = $value;
            $this->step = 'hostkey';
            $this->waiting = $app->read(['hostkey', $this->server], 'add-hostkey-probe')->id;
        } elseif ('root' === $field) {
            if (1 === preg_match(Inventory::PATH_PATTERN, $value)) {
                $this->root = $value;
                $this->discover($app);
            } else {
                $this->error = $app->t->t('add.bad_directory');
            }
        } elseif ('rename' === $field) {
            if (1 === preg_match(Inventory::ID_PATTERN, $value)) {
                $this->found[$this->cursor]['name'] = $value;
            } else {
                $this->error = $app->t->t('add.bad_name');
            }
        }

        return true;
    }

    private function hostKeyDone(App $app, Task $task): void
    {
        $json = (array) ($task->json ?? []);
        if (Task::DONE !== $task->state()) {
            $this->error = '' !== (string) ($json['error'] ?? '') ? (string) $json['error'] : $task->error();
            $this->step = 'failed';

            return;
        }
        if ( ! empty($json['known']) || ! empty($json['accepted'])) {
            $this->fingerprints = [];
            $this->discover($app);

            return;
        }
        $this->fingerprints = array_values(array_map('strval', (array) ($json['fingerprints'] ?? [])));
    }

    private function discover(App $app): void
    {
        $this->step = 'discover';
        $this->found = [];
        $this->warnings = [];
        $this->error = '';
        $this->waiting = $app->read($this->discoverArgs(), 'add-discover')->id;
    }

    private function discovered(App $app, Task $task): void
    {
        if (Task::DONE !== $task->state() || ! is_array($task->json)) {
            $this->error = $task->error();
            $this->step = 'none';

            return;
        }
        $known = $app->inventory()->instances();
        foreach ($task->json as $instance) {
            if ( ! is_array($instance)) {
                continue;
            }
            $id = (string) ($instance['id'] ?? '');
            $this->found[] = [
                'found' => $id,
                'name' => $id,
                'keep' => ! isset($known[$id]),
                'prod' => false,
                'url' => (string) ($instance['url'] ?? ''),
                'version' => (string) ($instance['version'] ?? ''),
                'htdocs' => (string) ($instance['htdocs'] ?? ''),
                'owner' => (string) ($instance['documents_owner'] ?? ''),
            ];
        }
        foreach ($task->stderrTail(5) as $line) {
            $this->warnings[] = $line;
        }
        $this->cursor = 0;
        $this->step = [] === $this->found ? 'none' : 'choose';
    }

    private function added(App $app, Task $task): void
    {
        if (Task::DONE !== $task->state()) {
            $this->error = $task->error();
            $this->step = 'choose';

            return;
        }
        $app->reloadInventory();
        $added = (array) ($task->json['added'] ?? []);
        $first = (string) ($added[0]['id'] ?? '');
        $root = $app->screens()[0] ?? null;
        while (count($app->screens()) > 1) {
            $app->pop();
        }
        if ($root instanceof WelcomeScreen) {
            $app->replace(new FleetScreen());
        }
        $app->toast($app->t->t('add.done', count($added), $this->server));
        if ('' !== $first) {
            $app->push(new InstanceScreen($first));
        }
    }

    /**
     * @return list<string>
     */
    private function discoverArgs(): array
    {
        $args = ['discover', $this->server, '--root='.$this->root, '--php='.$this->php];
        if ($this->sudo) {
            $args[] = '--sudo';
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    private function addArgs(App $app): array
    {
        $args = ['add', $this->server, '--root='.$this->root, '--php='.$this->php];
        if ($this->sudo) {
            $args[] = '--sudo';
        }
        $kept = array_values(array_filter($this->found, static fn (array $f): bool => $f['keep']));
        if ([] !== $kept) {
            $args[] = '--instances='.implode(',', array_column($kept, 'found'));
        }
        $renames = [];
        $prod = [];
        foreach ($kept as $found) {
            if ($found['name'] !== $found['found']) {
                $renames[] = $found['found'].'='.$found['name'];
            }
            if ($found['prod']) {
                $prod[] = $found['found'];
            }
        }
        if ([] !== $renames) {
            $args[] = '--rename='.implode(',', $renames);
        }
        if ([] !== $prod) {
            $args[] = '--prod='.implode(',', $prod);
        }

        return $args;
    }

    private function state(string $step): string
    {
        if ($this->step === $step) {
            return null !== $this->waiting ? 'running' : ('' !== $this->error ? 'failed' : 'pending');
        }

        return $this->stepDone($step) ? 'done' : 'pending';
    }

    private function stepDone(string $step): bool
    {
        $order = ['server', 'hostkey', 'discover', 'none', 'choose', 'adding'];

        return array_search($this->step, $order, true) > array_search($step, $order, true) && 'failed' !== $this->step;
    }

    private function stepLine(string $label, string $state, string $detail): Line
    {
        return Line::of('   ')->append(Signs::step($state))->add(' '.Line::pad($label, 22))->add($detail, 'dim');
    }
}
