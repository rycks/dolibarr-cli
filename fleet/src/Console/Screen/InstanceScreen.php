<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\Actions;
use Dolifleet\Console\App;
use Dolifleet\Console\Data\Checks;
use Dolifleet\Console\Data\Row;
use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Tasks\Command;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\ListControls;
use Dolifleet\Console\View\Signs;
use Dolifleet\Console\View\Table;
use Dolifleet\Store\Journal;

/**
 * Card of one instance, seven numbered tabs, each one a dolicli command (shown on line 2).
 * Lists load in the background the first time a tab opens; r loads them again.
 */
final class InstanceScreen extends Screen implements TargetAware
{
    public const TABS = [1 => 'summary', 2 => 'modules', 3 => 'users', 4 => 'cron', 5 => 'backups', 6 => 'journal', 7 => 'monitor'];

    private int $tab = 1;

    private bool $externalOnly = true;

    /** @var array<int, string> tab => id of the task loading it */
    private array $loading = [];

    /** @var array<int, Task> tab => task that loaded it */
    private array $loaded = [];

    /** @var array<int, int> */
    private array $cursor = [];

    /** @var array<int, int> */
    private array $offset = [];

    private bool $refreshed = false;

    /** @var array<int, ListControls> tab => filter and sort of its list */
    private array $controls = [];

    /** @var array<string, array<string, string>> tab => sortable field => key of its column label */
    private const SORTABLE = [
        'modules' => ['name' => 'col.module', 'version' => 'col.version', 'active' => 'col.active', 'external' => 'col.external', 'publisher' => 'col.publisher'],
        'users' => ['login' => 'col.login', 'lastname' => 'col.name', 'admin' => 'col.admin', 'status' => 'col.status', 'email' => 'col.email'],
        'cron' => ['label' => 'col.label', 'status' => 'col.status', 'frequency' => 'col.frequency', 'last_run' => 'col.last_run', 'last_result' => 'col.result', 'next_run' => 'col.next_run'],
        'backups' => ['file' => 'col.file', 'kind' => 'col.kind', 'bytes' => 'col.size', 'date' => 'col.date'],
        'journal' => ['date' => 'col.date', 'phase' => 'col.phase', 'command' => 'col.command', 'operator' => 'col.who'],
        'monitor' => ['check' => 'col.check', 'status' => 'col.state', 'collected_at' => 'col.collected'],
    ];

    public function __construct(public readonly string $id, int $tab = 1)
    {
        $this->tab = isset(self::TABS[$tab]) ? $tab : 1;
    }

    public function breadcrumb(App $app): array
    {
        $parts = [$this->id];
        if (1 !== $this->tab) {
            $parts[] = $app->t->t('tab.'.self::TABS[$this->tab]);
        }

        return $parts;
    }

    public function command(App $app): array
    {
        return match (self::TABS[$this->tab]) {
            'summary' => Command::status($this->id),
            'journal' => Command::log($this->id),
            default => $this->listCommand(),
        };
    }

    public function counters(App $app): string
    {
        $row = $app->row($this->id);
        if (null === $row) {
            return '';
        }
        $text = Signs::sign($row->status).' '.$app->t->t('state.short.'.('' !== $row->unreachable ? 'unreachable' : $row->status));
        if (null !== $row->age) {
            $text .= '   '.$app->t->t('fleet.state_age', $app->t->ago($row->age));
        }
        if (1 !== $this->tab && '' !== $this->controls()->summary($app->t)) {
            $text = $this->controls()->summary($app->t).'   '.$text;
        }

        return trim($text);
    }

    public function prod(App $app): bool
    {
        return $app->isProd($this->id);
    }

    public function target(App $app): string
    {
        return $this->id;
    }

    public function captures(): bool
    {
        return 1 !== $this->tab && $this->controls()->captures();
    }

    public function keys(App $app): array
    {
        if ($this->captures()) {
            return $this->controls()->keys($app->t);
        }
        $keys = [];
        $row = $app->row($this->id);
        if (null !== $row && '' !== $row->unreachable) {
            $keys[] = ['e', $app->t->t('key.error')];
        }
        if (1 !== $this->tab) {
            $keys[] = [$app->t->t('key.enter'), $app->t->t('key.detail')];
        }
        if (1 !== $this->tab) {
            $keys = array_merge($keys, $this->controls()->keys($app->t));
        }
        if (2 === $this->tab) {
            $keys[] = ['x', $app->t->t($this->externalOnly ? 'key.all_modules' : 'key.external_modules')];
        }
        $keys[] = ['b', $app->t->t('fix.b')];
        $keys[] = ['m', $app->t->t('fix.m')];
        $keys[] = ['u', $app->t->t('fix.u')];
        $keys[] = ['1-7', $app->t->t('key.tabs')];
        $keys[] = ['r', $app->t->t('key.refresh')];
        $keys[] = ['y', $app->t->t('key.copy')];

        return $keys;
    }

    public function handle(App $app, string $key): bool
    {
        if (1 !== $this->tab && ($this->captures() || in_array($key, ['/', 's', 'S', 'esc'], true)) && $this->controls()->handle($key)) {
            $this->cursor[$this->tab] = 0;
            $this->offset[$this->tab] = 0;

            return true;
        }
        if (1 === preg_match('/^[1-7]$/', $key)) {
            $this->tab = (int) $key;

            return true;
        }
        switch ($key) {
            case 'tab':
                $this->tab = $this->tab % 7 + 1;

                return true;
            case 'shift-tab':
                $this->tab = (($this->tab + 5) % 7) + 1;

                return true;
            case 'b':
                $app->push(Actions::backup($app, [$this->id]));

                return true;
            case 'm':
                $app->push(Actions::maintenance($app, [$this->id]));

                return true;
            case 'u':
                $app->push(Actions::upgrade($app, $this->id));

                return true;
            case 'x':
                if (2 === $this->tab) {
                    $this->externalOnly = ! $this->externalOnly;
                    unset($this->loaded[2], $this->loading[2]);
                }

                return true;
            case 'r':
                $app->refresh([$this->id]);
                unset($this->loaded[$this->tab], $this->loading[$this->tab]);

                return true;
            case 'e':
                $row = $app->row($this->id);
                if (null !== $row && '' !== $row->unreachable) {
                    $app->push(ErrorScreen::forInstance($app, $this->id, $row->unreachable, ''));
                }

                return true;
        }
        if (1 === $this->tab) {
            return false;
        }
        $records = $this->records($app);
        $cursor = $this->cursor[$this->tab] ?? 0;
        $count = count($records);
        $moved = match ($key) {
            'up' => max(0, $cursor - 1),
            'down' => min(max(0, $count - 1), $cursor + 1),
            'pgup' => max(0, $cursor - 10),
            'pgdn' => min(max(0, $count - 1), $cursor + 10),
            'home' => 0,
            'end' => max(0, $count - 1),
            default => null,
        };
        if (null !== $moved) {
            $this->cursor[$this->tab] = $moved;

            return true;
        }
        if ('enter' === $key && isset($records[$cursor])) {
            $app->push(new DetailScreen([$this->id, $app->t->t('tab.'.self::TABS[$this->tab])], $records[$cursor], $this->command($app), $this->prod($app)));

            return true;
        }

        return false;
    }

    public function taskFinished(App $app, Task $task): void
    {
        foreach ($this->loading as $tab => $id) {
            if ($id === $task->id) {
                $this->loaded[$tab] = $task;
                unset($this->loading[$tab]);
            }
        }
    }

    public function body(App $app, int $width, int $height): array
    {
        if ( ! $this->refreshed) {
            $this->refreshed = true;
            $app->refresh([$this->id]);
        }
        $row = $app->row($this->id);
        if (null === $row) {
            return [Line::of(' '.$app->t->t('instance.unknown', $this->id), 'error')];
        }
        $lines = [$this->tabsLine($app), Line::of($app->style->rule($width, $row->prod), $row->prod ? 'frame-prod' : 'frame')];
        $input = 1 === $this->tab ? null : $this->controls()->inputLine();
        $room = $height - count($lines) - (null === $input ? 0 : 2);
        $content = match (self::TABS[$this->tab]) {
            'summary' => $this->summary($app, $row),
            'journal' => $this->table($app, $width, $room),
            'monitor' => $this->table($app, $width, $room),
            default => $this->listTab($app, $width, $room),
        };
        if (null !== $input) {
            $content = array_slice($content, 0, $room);
            while (count($content) < $room) {
                $content[] = Line::blank();
            }
            $content[] = Line::blank();
            $content[] = $input;
        }

        return array_merge($lines, $content);
    }

    private function tabsLine(App $app): Line
    {
        $line = Line::of(' ');
        foreach (self::TABS as $number => $name) {
            $label = $number.' '.$app->t->t('tab.'.$name);
            $line = $number === $this->tab ? $line->add('['.$label.']', 'reverse')->add(' ') : $line->add(' '.$label.'  ');
        }

        return $line;
    }

    /**
     * @return list<string>
     */
    private function listCommand(): array
    {
        return match (self::TABS[$this->tab]) {
            'modules' => Command::exec($this->id, $this->externalOnly ? ['module', 'list', '--external'] : ['module', 'list']),
            'users' => Command::exec($this->id, ['user', 'list']),
            'cron' => Command::exec($this->id, ['cron', 'list']),
            'backups' => Command::exec($this->id, ['backup', 'list']),
            default => Command::exec($this->id, ['monitor', 'show']),
        };
    }

    /**
     * @return list<Line>
     */
    private function summary(App $app, Row $row): array
    {
        $t = $app->t;
        $fields = self::summaryFields($t, $row);

        $labelWidth = 0;
        foreach ($fields as [$label]) {
            $labelWidth = max($labelWidth, Line::widthOf($label));
        }
        $lines = [];
        foreach ($fields as [$label, $content, $link]) {
            $line = Line::of('  '.Line::pad($label, $labelWidth).'  ', 'bold')->append($content);
            if ('' !== $link) {
                $line = $line->add('   -> '.$link, 'dim');
            }
            $lines[] = $line;
        }
        if ([] !== $row->problems) {
            $lines[] = Line::blank();
            $lines[] = Line::of('  '.$t->t('summary.problems'), 'bold');
            foreach ($row->problems as $problem) {
                $lines[] = Line::of('  '.Signs::sign($problem->status).' ', Signs::style($problem->status))->add($problem->text, Signs::style($problem->status))->add('   -> '.$problem->fix, 'dim');
            }
        }

        return $lines;
    }

    /**
     * Fields of the summary from the local state: label, content, key of the tab that details
     * it. Shared with the web dashboard, which shows the same fields.
     *
     * @return list<array{0: string, 1: Line, 2: string}>
     */
    public static function summaryFields(Translator $t, Row $row): array
    {
        $instance = $row->instance;
        $value = static fn (string $check): mixed => $row->value($check);
        $fields = [];
        if ('' !== $row->unreachable) {
            $fields[] = [$t->t('summary.unreachable'), Line::of($row->unreachable, 'unknown'), 'e'];
        }
        $fields[] = [$t->t('summary.url'), Line::of((string) ($row->url ?? '-')), ''];
        $fields[] = [$t->t('summary.server'), Line::of($t->t('summary.server_value', $instance->server, '' !== $instance->runAs ? $instance->runAs : '?', '' !== (string) $value('php.cli_version') ? (string) $value('php.cli_version') : ('' !== $instance->php ? $instance->php : 'php'))), ''];
        $dolibarr = $t->t('summary.dolibarr_value', '' !== $row->version() ? $row->version() : '?', (string) ($value('dolibarr.db_version') ?? '?'));
        if (true === $value('security.prod_mode')) {
            $dolibarr .= ', '.$t->t('summary.prod_mode');
        }
        $fields[] = [$t->t('summary.dolibarr'), Line::of($dolibarr), ''];
        $fields[] = [$t->t('summary.tags'), Line::of([] === $instance->tags ? '-' : implode(', ', $instance->tags)), ''];
        $external = $value('modules.external');
        $fields[] = [$t->t('summary.modules'), Line::of(is_array($external) ? $t->t('summary.modules_value', count($external)) : '-'), '2'];
        $fields[] = [$t->t('summary.users'), Line::of(is_numeric($value('users.active')) ? $t->t('summary.users_value', (int) $value('users.active')) : '-'), '3'];
        $cron = [];
        foreach (['failed', 'stale', 'stuck'] as $kind) {
            if (is_numeric($value('cron.'.$kind)) && (int) $value('cron.'.$kind) > 0) {
                $cron[] = $t->t('summary.cron_'.$kind, (int) $value('cron.'.$kind));
            }
        }
        $fields[] = [$t->t('summary.cron'), Line::of([] === $cron ? $t->t('summary.cron_ok') : implode(', ', $cron), [] === $cron ? '' : 'warn'), '4'];
        $backup = $row->backupAge();
        $backupStatus = (string) ($row->checks['backup.age']['status'] ?? 'ok');
        $measured = isset($row->checks['backup.age']);
        $fields[] = [$t->t('summary.backups'), Line::of( ! $measured ? '-' : (null === $backup ? $t->t('summary.backup_none') : $t->t('summary.backup_value', $t->duration($backup))), $measured ? Signs::style($backupStatus) : ''), '5'];
        $maintenance = $row->maintenance();
        $fields[] = [$t->t('summary.maintenance'), Line::of(null === $maintenance ? '-' : ($maintenance ? 'ON' : 'off'), true === $maintenance ? 'warn' : ''), 'm'];
        $disk = [];
        if (is_numeric($value('storage.free_bytes'))) {
            $disk[] = $t->t('summary.disk_free', $t->bytes((int) $value('storage.free_bytes')));
        }
        if (is_numeric($value('storage.documents_bytes'))) {
            $disk[] = $t->t('summary.disk_documents', $t->bytes((int) $value('storage.documents_bytes')));
        }
        if (is_numeric($value('storage.database_bytes'))) {
            $disk[] = $t->t('summary.disk_database', $t->bytes((int) $value('storage.database_bytes')));
        }
        $fields[] = [$t->t('summary.disk'), Line::of([] === $disk ? '-' : implode(', ', $disk)), ''];
        $modified = $value('filecheck.modified');
        $fields[] = [$t->t('summary.integrity'), Line::of(is_numeric($modified) ? $t->t('summary.integrity_value', (int) $modified, (int) ($value('filecheck.missing') ?? 0)) : '-', is_numeric($modified) && (int) $modified > 0 ? 'warn' : ''), '7'];

        return $fields;
    }

    /**
     * @return list<Line>
     */
    private function listTab(App $app, int $width, int $height): array
    {
        $task = $this->loaded[$this->tab] ?? null;
        if (null === $task) {
            if ( ! isset($this->loading[$this->tab])) {
                $this->loading[$this->tab] = $app->read($this->listCommand(), 'tab', [$this->id])->id;
            }

            return [Line::of('  ')->append(Signs::step('running'))->add(' '.$app->t->t('instance.loading'), 'running')];
        }
        if (Task::DONE !== $task->state()) {
            return [
                Line::of('  !! '.$task->error(), 'crit'),
                Line::blank(),
                Line::of('  '.$app->t->t('instance.load_failed_hint'), 'dim'),
            ];
        }
        $warnings = [];
        foreach ((array) ($task->outcome($this->id)['envelope']['warnings'] ?? []) as $warning) {
            $warnings[] = Line::of('  ! '.(string) ($warning['message'] ?? ''), 'warn');
        }
        $lines = $this->table($app, $width, $height - count($warnings));

        return array_merge($lines, $warnings);
    }

    /**
     * Records of the current tab as shown: filtered and sorted.
     *
     * @return list<array<string, mixed>>
     */
    private function records(App $app): array
    {
        return $this->controls()->apply($this->received($app));
    }

    private function controls(): ListControls
    {
        return $this->controls[$this->tab] ??= new ListControls(self::SORTABLE[self::TABS[$this->tab]] ?? []);
    }

    /**
     * Records of the current tab as received.
     *
     * @return list<array<string, mixed>>
     */
    private function received(App $app): array
    {
        $name = self::TABS[$this->tab];
        if ('journal' === $name) {
            return array_reverse((new Journal())->tail(200, $this->id));
        }
        if ('monitor' === $name) {
            $records = [];
            $row = $app->row($this->id);
            foreach (null === $row ? [] : $row->checks as $check => $state) {
                if (is_array($state)) {
                    $records[] = ['check' => (string) $check] + $state;
                }
            }
            usort($records, static fn (array $a, array $b): int => [Checks::gravity((string) ($b['status'] ?? '')), $a['check']] <=> [Checks::gravity((string) ($a['status'] ?? '')), $b['check']]);

            return $records;
        }
        $task = $this->loaded[$this->tab] ?? null;
        if (null === $task) {
            return [];
        }
        $key = match ($name) {
            'modules' => 'modules',
            'users' => 'users',
            'cron' => 'jobs',
            default => 'backups',
        };

        return array_values(array_filter((array) ($task->data($this->id)[$key] ?? []), 'is_array'));
    }

    /**
     * @return list<Line>
     */
    private function table(App $app, int $width, int $height): array
    {
        $t = $app->t;
        $records = $this->records($app);
        $name = self::TABS[$this->tab];
        $table = new Table();
        switch ($name) {
            case 'modules':
                $table->column('name', $t->t('col.module'), 10, 30, 0)->column('version', $t->t('col.version'), 7, 12, 2)
                    ->column('active', $t->t('col.active'), 5, 6, 1)->column('external', $t->t('col.external'), 5, 7, 3)
                    ->column('publisher', $t->t('col.publisher'), 8, 40, 4, 'left', true);
                foreach ($records as $module) {
                    $table->row([
                        'name' => (string) ($module['name'] ?? $module['class'] ?? ''),
                        'version' => (string) ($module['version'] ?? ''),
                        'active' => $t->t(empty($module['active']) ? 'value.no' : 'value.yes'),
                        'external' => $t->t(empty($module['external']) ? 'value.no' : 'value.yes'),
                        'publisher' => (string) ($module['publisher'] ?? ''),
                    ]);
                }
                break;
            case 'users':
                $table->column('login', $t->t('col.login'), 8, 24, 0)->column('name', $t->t('col.name'), 8, 30, 1)
                    ->column('admin', $t->t('col.admin'), 5, 10, 2)->column('status', $t->t('col.status'), 6, 10, 0)
                    ->column('email', $t->t('col.email'), 8, 40, 3, 'left', true);
                foreach ($records as $user) {
                    $enabled = 'enabled' === ($user['status'] ?? '');
                    $admin = $user['admin'] ?? 'no';
                    $table->row([
                        'login' => (string) ($user['login'] ?? ''),
                        'name' => trim((string) ($user['firstname'] ?? '').' '.(string) ($user['lastname'] ?? '')),
                        'admin' => in_array($admin, [false, 0, '0', 'no', ''], true) ? $t->t('value.no') : $t->t('value.yes'),
                        'status' => Line::of($t->t($enabled ? 'value.enabled' : 'value.disabled'), $enabled ? '' : 'dim'),
                        'email' => (string) ($user['email'] ?? ''),
                    ]);
                }
                break;
            case 'cron':
                $table->column('label', $t->t('col.label'), 12, 40, 0)->column('status', $t->t('col.status'), 6, 10, 2)
                    ->column('frequency', $t->t('col.frequency'), 8, 20, 4)->column('last_run', $t->t('col.last_run'), 10, 16, 3)
                    ->column('result', $t->t('col.result'), 6, 10, 1)->column('next_run', $t->t('col.next_run'), 10, 16, 5)
                    ->column('flags', '', 6, 30, 0, 'left', true);
                foreach ($records as $job) {
                    $flags = [];
                    foreach (['stuck', 'failed', 'late'] as $flag) {
                        if ( ! empty($job[$flag])) {
                            $flags[] = $t->t('cron.flag.'.$flag);
                        }
                    }
                    $result = $job['last_result'] ?? null;
                    $table->row([
                        'label' => (string) ($job['label'] ?? ''),
                        'status' => $t->t('cron.status.'.(in_array($job['status'] ?? '', ['enabled', 'disabled', 'archived'], true) ? $job['status'] : 'disabled')),
                        'frequency' => (string) ($job['frequency'] ?? ''),
                        'last_run' => self::date($app, $job['last_run'] ?? null),
                        'result' => Line::of(null === $result ? '-' : $t->t('cron.result.'.('ok' === $result ? 'ok' : 'failed')), 'failed' === $result ? 'crit' : ''),
                        'next_run' => self::date($app, $job['next_run'] ?? null),
                        'flags' => Line::of(implode(', ', $flags), [] === $flags ? '' : 'warn'),
                    ]);
                }
                break;
            case 'backups':
                $table->column('file', $t->t('col.file'), 12, 50, 0)->column('kind', $t->t('col.kind'), 6, 12, 2)
                    ->column('size', $t->t('col.size'), 6, 10, 1, 'right')->column('date', $t->t('col.date'), 10, 16, 3)
                    ->column('age', $t->t('col.age'), 5, 8, 0, 'left', true);
                foreach ($records as $backup) {
                    $table->row([
                        'file' => (string) ($backup['file'] ?? ''),
                        'kind' => $t->t('backup.kind.'.(in_array($backup['kind'] ?? '', ['database', 'documents', 'modules'], true) ? $backup['kind'] : 'unknown')),
                        'size' => $t->bytes((int) ($backup['bytes'] ?? 0)),
                        'date' => self::date($app, $backup['date'] ?? null),
                        'age' => $t->duration((int) ($backup['age_seconds'] ?? 0)),
                    ]);
                }
                break;
            case 'journal':
                $table->column('date', $t->t('col.date'), 10, 16, 0)->column('phase', $t->t('col.phase'), 6, 10, 2)
                    ->column('command', $t->t('col.command'), 10, 30, 0)->column('who', $t->t('col.who'), 4, 16, 3)
                    ->column('summary', $t->t('col.summary'), 10, 200, 1, 'left', true);
                foreach ($records as $entry) {
                    $result = 'result' === ($entry['phase'] ?? '');
                    $failed = $result && 0 !== (int) ($entry['exit_code'] ?? 0);
                    $table->row([
                        'date' => self::date($app, $entry['date'] ?? null),
                        'phase' => $t->t('journal.phase.'.($result ? 'result' : 'intention')),
                        'command' => (string) ($entry['command'] ?? ''),
                        'who' => (string) ($entry['operator'] ?? '').('' !== (string) ($entry['via'] ?? '') && 'cli' !== $entry['via'] ? ' ('.$entry['via'].')' : ''),
                        'summary' => Line::of($result ? (string) ($entry['summary'] ?? '') : implode(' ', array_map('strval', (array) ($entry['arguments'] ?? []))), $failed ? 'crit' : ''),
                    ]);
                }
                break;
            default:
                $table->column('check', $t->t('col.check'), 12, 34, 0)->column('state', $t->t('col.state'), 9, 14, 0)
                    ->column('value', $t->t('col.value'), 6, 20, 1)->column('collected', $t->t('col.collected'), 6, 12, 3)
                    ->column('message', $t->t('col.message'), 10, 200, 2, 'left', true);
                foreach ($records as $check) {
                    $name = (string) $check['check'];
                    $collected = strtotime((string) ($check['collected_at'] ?? ''));
                    $table->row([
                        'check' => $app->checks->label($name),
                        'state' => Signs::state($t, (string) ($check['status'] ?? 'unknown')),
                        'value' => $app->checks->value($name, $check),
                        'collected' => false === $collected ? '' : $t->ago(time() - $collected),
                        'message' => Line::of((string) ($check['message'] ?? ''), 'dim'),
                    ]);
                }
        }
        if (0 === $table->count()) {
            return [Line::of('  '.$t->t('instance.empty'), 'dim')];
        }
        $cursor = min($this->cursor[$this->tab] ?? 0, $table->count() - 1);
        $this->cursor[$this->tab] = $cursor;
        $this->offset[$this->tab] = Table::scroll($cursor, $this->offset[$this->tab] ?? 0, $height - 1, $table->count());

        return $table->render($width, $height, $cursor, $this->offset[$this->tab]);
    }

    private static function date(App $app, mixed $value): string
    {
        if ( ! is_string($value) || '' === $value) {
            return '-';
        }
        $time = strtotime($value);

        return false === $time ? $value : $app->t->date($time);
    }
}
