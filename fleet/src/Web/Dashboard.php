<?php

declare(strict_types=1);

namespace Dolifleet\Web;

use Dolifleet\Console\Data\Checks;
use Dolifleet\Console\Data\Row;
use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Screen\InstanceScreen;
use Dolifleet\Console\Tasks\Command;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Signs;
use Dolifleet\Inventory\Inventory;
use Dolifleet\Paths;
use Dolifleet\Store\Journal;
use Dolifleet\Store\StateStore;
use InvalidArgumentException;
use RuntimeException;

/**
 * Local dashboard of dolifleet serve, the mirror of the console: same columns, words, signs,
 * texts in the language of the operator, and the equivalent command at the top of each page.
 * Served by the built-in server of PHP on 127.0.0.1 only (an SSH tunnel for remote access).
 *
 * - The token shown in the terminal is exchanged on the first request for an HttpOnly,
 *   SameSite=Strict cookie, then the browser is redirected to a URL without it.
 * - Host and Origin are checked (DNS rebinding); actions are POST with the token in a header.
 * - Data comes from the servers and is untrusted: everything is escaped, the page sets a strict
 *   CSP and loads nothing external.
 * - The built-in server handles one request at a time: actions start the CLI in the background
 *   and are followed in the list of operations. No action exists only here.
 * - A write is confirmed in the page with the rule of the console (a protected instance: its
 *   name typed; otherwise a simple confirmation), then the pilot checks the same answer again
 *   (--confirm) and journals the write as coming from the dashboard (DOLIFLEET_FRONTEND).
 */
final class Dashboard
{
    public const COOKIE = 'dolifleet';

    public const FRONTEND = 'dashboard';

    /** action => whether it writes (confirmed, journaled). */
    public const ACTIONS = [
        'maintenance-on' => true,
        'maintenance-off' => true,
        'collect' => false,
        'backup' => true,
    ];

    private readonly Translator $t;

    private readonly Checks $checks;

    public function __construct(
        private readonly string $token,
        private readonly int $port,
        private readonly string $inventoryFile,
        private readonly string $dolifleet,
        ?Translator $translator = null,
    ) {
        $this->t = $translator ?? new Translator(Translator::REFERENCE);
        $this->checks = new Checks($this->t);
    }

    /**
     * @param array<string, string> $server $_SERVER
     * @param array<string, mixed> $query $_GET
     * @param array<string, mixed> $post $_POST
     * @param array<string, mixed> $cookies $_COOKIE
     */
    public function handle(array $server, array $query, array $post, array $cookies): Response
    {
        $host = (string) ($server['HTTP_HOST'] ?? '');
        if ( ! in_array($host, ['127.0.0.1:'.$this->port, 'localhost:'.$this->port], true)) {
            return $this->plain(421, $this->t->t('web.error.host'));
        }
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        if ('' !== $origin && ! in_array($origin, ['http://127.0.0.1:'.$this->port, 'http://localhost:'.$this->port], true)) {
            return $this->plain(403, $this->t->t('web.error.origin'));
        }
        $path = (string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');

        if (isset($query['token'])) {
            if ( ! hash_equals($this->token, (string) $query['token'])) {
                return $this->plain(403, $this->t->t('web.error.token'));
            }

            return new Response(303, '', [
                'Location' => '/',
                'Set-Cookie' => self::COOKIE.'='.$this->session().'; Path=/; HttpOnly; SameSite=Strict',
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }
        if ( ! hash_equals($this->session(), (string) ($cookies[self::COOKIE] ?? ''))) {
            return $this->plain(401, $this->t->t('web.error.login'));
        }

        if ('/app.js' === $path) {
            return $this->asset('app.js', 'text/javascript');
        }
        if ('/app.css' === $path) {
            return $this->asset('app.css', 'text/css');
        }
        if ('POST' === $method) {
            if ( ! hash_equals($this->csrf(), (string) ($server['HTTP_X_DOLIFLEET_TOKEN'] ?? ''))) {
                return $this->plain(403, $this->t->t('web.error.action_token'));
            }
            if ('/action' === $path) {
                return $this->action($post);
            }

            return $this->plain(404, $this->t->t('web.error.not_found'));
        }
        if ('/' === $path) {
            return $this->fleet();
        }
        if (1 === preg_match('#^/instance/([a-z0-9][a-z0-9_-]*)$#', $path, $m)) {
            return $this->instance($m[1]);
        }
        if ('/tasks' === $path) {
            return $this->page([$this->t->t('op.title')], false, '', ['log'], $this->tasks());
        }
        if ('/inventory' === $path) {
            return $this->page([$this->t->t('web.nav.inventory')], false, '', ['config', 'validate'], $this->inventoryView());
        }

        return $this->plain(404, $this->t->t('web.error.not_found'));
    }

    /**
     * Value of the cookie: derived from the token, so the token itself never travels again.
     */
    public function session(): string
    {
        return hash_hmac('sha256', 'session', $this->token);
    }

    /**
     * Token of the action requests (header X-Dolifleet-Token), embedded in the page.
     */
    public function csrf(): string
    {
        return hash_hmac('sha256', 'csrf', $this->token);
    }

    public static function e(mixed $value): string
    {
        $text = is_scalar($value) || null === $value ? (string) $value : (string) json_encode($value);

        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Answer the pilot expects to the confirmation of a write on one instance, as in the
     * console: the name of a protected instance, yes otherwise.
     */
    public static function expectedAnswer(bool $protected, string $instance): string
    {
        return $protected ? $instance : 'yes';
    }

    /**
     * The command an action runs: the arguments as typed (shown in the operations), the full
     * command line run (with --confirm for a write, never --yes) and its environment.
     *
     * @return array{shown: list<string>, command: list<string>, env: array<string, string>}
     */
    public function actionCommand(string $action, string $target, string $answer): array
    {
        $args = match ($action) {
            'maintenance-on' => Command::maintenance(true, $target),
            'maintenance-off' => Command::maintenance(false, $target),
            'backup' => Command::backup($target),
            'collect' => array_merge(Command::status($target), ['--refresh']),
            default => throw new InvalidArgumentException("Unknown action '{$action}'."),
        };
        $options = self::ACTIONS[$action] ? ['--confirm='.$answer] : [];

        return [
            'shown' => $args,
            'command' => array_merge([PHP_BINARY, $this->dolifleet, '--no-interaction'], Command::withOptions($args, $options)),
            'env' => ['DOLIFLEET_FRONTEND' => self::FRONTEND, 'DOLIFLEET_INVENTORY' => $this->inventoryFile],
        ];
    }

    public static function tasksDir(): string
    {
        return Paths::dataDir().'/serve-tasks';
    }

    private function fleet(): Response
    {
        $inventory = Inventory::load($this->inventoryFile);
        $rows = Row::all($inventory, new StateStore(), $this->checks);
        usort($rows, static fn (Row $a, Row $b): int => [$b->gravity(), count($b->problems), $a->id()] <=> [$a->gravity(), count($a->problems), $b->id()]);

        $t = $this->t;
        $body = '';
        foreach ($rows as $row) {
            $problem = Line::blank();
            if ('never' === $row->status) {
                $problem = Line::of($t->t('fleet.never'), 'unknown');
            } elseif ([] !== $row->problems) {
                $first = $row->problems[0];
                $problem = Line::of($first->text, Signs::style($first->status));
                if (count($row->problems) > 1) {
                    $problem = $problem->add(' (+'.(count($row->problems) - 1).')', 'dim');
                }
            }
            $backup = $row->backupAge();
            $maintenance = $row->maintenance();
            $age = null === $row->age ? $t->t('fleet.never') : $t->t('fleet.state_age', $t->ago($row->age));
            $body .= '<tr>'
                .'<td class="p0"><a href="/instance/'.self::e($row->id()).'">'.self::e($row->id()).'</a>'.($row->prod ? ' <span class="prod">PROD</span>' : '').'</td>'
                .'<td class="p3">'.self::e($row->version()).'</td>'
                .'<td class="p5">'.self::e($row->php()).'</td>'
                .'<td class="p0 state" title="'.self::e($age).'">'.$this->line(Signs::rowState($t, $row)).'</td>'
                .'<td class="p4">'.$this->line(Line::of(null === $backup ? '' : $t->duration($backup), null !== $backup && $backup > 2 * 86400 ? 'warn' : '')).'</td>'
                .'<td class="p6">'.$this->line(Line::of(null === $maintenance ? '' : ($maintenance ? 'ON' : 'off'), true === $maintenance ? 'warn' : 'dim')).'</td>'
                .'<td class="p2">'.$this->line($problem).'</td>'
                .'<td class="actions">'.$this->buttons($row).'</td>'
                .'</tr>';
        }
        $table = '<table class="fleet"><thead><tr>'
            .'<th class="p0">'.self::e($t->t('col.instance')).'</th>'
            .'<th class="p3">'.self::e($t->t('col.version')).'</th>'
            .'<th class="p5">'.self::e($t->t('col.php')).'</th>'
            .'<th class="p0">'.self::e($t->t('col.state')).'</th>'
            .'<th class="p4">'.self::e($t->t('col.backup')).'</th>'
            .'<th class="p6">'.self::e($t->t('col.maintenance')).'</th>'
            .'<th class="p2">'.self::e($t->t('col.problem')).'</th>'
            .'<th class="actions">'.self::e($t->t('web.col.actions')).'</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table>';
        if ([] === $rows) {
            $table .= '<p class="st-dim">'.self::e($t->t('instance.empty')).'</p>';
        }

        $counters = $t->t('fleet.count', count($rows));
        $ages = array_filter(array_map(static fn (Row $r): ?int => $r->age, $rows), static fn (?int $a): bool => null !== $a);
        if ([] !== $ages) {
            $counters .= '   '.$t->t('fleet.state_age', $t->ago(min($ages)));
        }

        return $this->page([$t->t('fleet.all')], false, $counters, Command::status('all'), $table);
    }

    private function instance(string $id): Response
    {
        $t = $this->t;
        $inventory = Inventory::load($this->inventoryFile);
        if ( ! isset($inventory->instances()[$id])) {
            return $this->plain(404, $t->t('instance.unknown', $id));
        }
        $row = Row::one($inventory, new StateStore(), $this->checks, $inventory->instance($id));

        $summary = '';
        foreach (InstanceScreen::summaryFields($t, $row) as [$label, $content]) {
            $summary .= '<tr><th scope="row">'.self::e($label).'</th><td>'.$this->line($content).'</td></tr>';
        }
        $out = '<div class="buttons">'.$this->buttons($row).'</div>'
            .'<section><h2>'.self::e($t->t('tab.summary')).'</h2>'
            .'<table class="summary"><tbody>'.$summary.'</tbody></table>';
        if ([] !== $row->problems) {
            $out .= '<h3>'.self::e($t->t('summary.problems')).'</h3><ul class="problems">';
            foreach ($row->problems as $problem) {
                $out .= '<li>'.$this->line(Line::of(trim(Signs::sign($problem->status)).' ', Signs::style($problem->status))->add($problem->text, Signs::style($problem->status))).'</li>';
            }
            $out .= '</ul>';
        }
        $out .= '</section>';

        $out .= '<section><h2>'.self::e($t->t('tab.monitor')).'</h2>'.$this->commandLine(Command::exec($id, ['monitor', 'show'])).$this->monitoring($row).'</section>';
        $out .= '<section><h2>'.self::e($t->t('tab.journal')).'</h2>'.$this->commandLine(Command::log($id)).$this->journal($id).'</section>';

        $counters = trim(Signs::rowState($t, $row)->text());
        if (null !== $row->age) {
            $counters .= '   '.$t->t('fleet.state_age', $t->ago($row->age));
        }

        return $this->page([$id], $row->prod, $counters, Command::status($id), $out);
    }

    /**
     * Monitoring checks of the last state, worst first, as in the monitoring tab of the console.
     */
    private function monitoring(Row $row): string
    {
        $t = $this->t;
        $records = [];
        foreach ($row->checks as $check => $state) {
            if (is_array($state)) {
                $records[] = ['check' => (string) $check] + $state;
            }
        }
        usort($records, static fn (array $a, array $b): int => [Checks::gravity((string) ($b['status'] ?? '')), $a['check']] <=> [Checks::gravity((string) ($a['status'] ?? '')), $b['check']]);
        if ([] === $records) {
            return '<p class="st-dim">'.self::e($t->t('instance.empty')).'</p>';
        }
        $body = '';
        foreach ($records as $check) {
            $name = (string) $check['check'];
            $collected = strtotime((string) ($check['collected_at'] ?? ''));
            $body .= '<tr>'
                .'<td class="p0">'.self::e($this->checks->label($name)).'</td>'
                .'<td class="p0 state">'.$this->line(Signs::state($t, (string) ($check['status'] ?? 'unknown'))).'</td>'
                .'<td class="p1">'.self::e($this->checks->value($name, $check)).'</td>'
                .'<td class="p3">'.self::e(false === $collected ? '' : $t->ago(time() - $collected)).'</td>'
                .'<td class="p2 st-dim">'.self::e($check['message'] ?? '').'</td>'
                .'</tr>';
        }

        return '<table><thead><tr>'
            .'<th class="p0">'.self::e($t->t('col.check')).'</th>'
            .'<th class="p0">'.self::e($t->t('col.state')).'</th>'
            .'<th class="p1">'.self::e($t->t('col.value')).'</th>'
            .'<th class="p3">'.self::e($t->t('col.collected')).'</th>'
            .'<th class="p2">'.self::e($t->t('col.message')).'</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    /**
     * Journal of the instance, most recent first, as in the journal tab of the console.
     */
    private function journal(string $id): string
    {
        $t = $this->t;
        $entries = array_reverse((new Journal())->tail(50, $id));
        if ([] === $entries) {
            return '<p class="st-dim">'.self::e($t->t('instance.empty')).'</p>';
        }
        $body = '';
        foreach ($entries as $entry) {
            $result = 'result' === ($entry['phase'] ?? '');
            $failed = $result && 0 !== (int) ($entry['exit_code'] ?? 0);
            $via = (string) ($entry['via'] ?? '');
            $time = strtotime((string) ($entry['date'] ?? ''));
            $body .= '<tr>'
                .'<td class="p0">'.self::e(false === $time ? (string) ($entry['date'] ?? '-') : $t->date($time)).'</td>'
                .'<td class="p2">'.self::e($t->t('journal.phase.'.($result ? 'result' : 'intention'))).'</td>'
                .'<td class="p0">'.self::e($entry['command'] ?? '').'</td>'
                .'<td class="p3">'.self::e((string) ($entry['operator'] ?? '').('' !== $via && 'cli' !== $via ? ' ('.$via.')' : '')).'</td>'
                .'<td class="p1">'.$this->line(Line::of($result ? (string) ($entry['summary'] ?? '') : implode(' ', array_map('strval', (array) ($entry['arguments'] ?? []))), $failed ? 'crit' : '')).'</td>'
                .'</tr>';
        }

        return '<table><thead><tr>'
            .'<th class="p0">'.self::e($t->t('col.date')).'</th>'
            .'<th class="p2">'.self::e($t->t('col.phase')).'</th>'
            .'<th class="p0">'.self::e($t->t('col.command')).'</th>'
            .'<th class="p3">'.self::e($t->t('col.who')).'</th>'
            .'<th class="p1">'.self::e($t->t('col.summary')).'</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    /**
     * Actions of one instance. Maintenance toggles like the m key of the console (off when it
     * is on, on otherwise); it is the only action offered on a phone (CSS). The question and
     * the confirmation mode are those of the console for one instance.
     */
    private function buttons(Row $row): string
    {
        $t = $this->t;
        $id = $row->id();
        $on = true !== $row->maintenance();
        $actions = [
            [$on ? 'maintenance-on' : 'maintenance-off', $t->t($on ? 'action.maintenance_on' : 'action.maintenance_off'), $t->t($on ? 'action.maintenance_on.summary' : 'action.maintenance_off.summary'), 'maintenance'],
            ['backup', $t->t('action.backup'), $t->t('action.backup.summary'), ''],
            ['collect', $t->t('web.action.collect'), '', ''],
        ];
        $out = '';
        foreach ($actions as [$action, $title, $summary, $class]) {
            $mode = 'none';
            $question = '';
            if (self::ACTIONS[$action]) {
                $mode = $row->prod ? 'type' : 'confirm';
                $question = $summary."\n\n".($row->prod ? $t->t('preview.type_name', $id) : $t->t('web.confirm', $title, $id));
            }
            $out .= '<button type="button" class="action'.('' !== $class ? ' '.$class : '').'" data-action="'.self::e($action).'" data-target="'.self::e($id).'"'
                .' data-mode="'.$mode.'" data-question="'.self::e($question).'">'.self::e($title).'</button> ';
        }

        return $out;
    }

    /**
     * Operations started from the dashboard, most recent first: state, command as typed, log.
     */
    private function tasks(): string
    {
        $t = $this->t;
        $out = '';
        foreach (array_reverse(glob(self::tasksDir().'/*.log') ?: []) as $file) {
            $base = mb_substr($file, 0, -4);
            $meta = is_file($base.'.json') ? json_decode((string) file_get_contents($base.'.json'), true) : null;
            $exit = is_file($base.'.exit') ? trim((string) file_get_contents($base.'.exit')) : null;
            $state = null === $exit ? 'running' : ('0' === $exit ? 'done' : 'failed');
            $command = is_array($meta) && is_array($meta['args'] ?? null) ? Command::line(array_values(array_map('strval', $meta['args']))) : basename($base);
            $started = is_array($meta) && is_numeric($meta['started_at'] ?? null) ? $t->t('op.started_at', $t->date((int) $meta['started_at'])) : '';
            $out .= '<section class="task"><h2><span class="state">'.$this->line(Signs::state($t, is_array($meta) ? $state : 'unknown')).'</span> <code>'.self::e($command).'</code></h2>'
                .('' !== $started ? '<p class="st-dim">'.self::e($started).'</p>' : '')
                .'<pre>'.self::e(mb_substr((string) file_get_contents($file), -4000)).'</pre></section>';
        }

        return '' === $out ? '<p class="st-dim">'.self::e($t->t('web.tasks.none')).'</p>' : $out;
    }

    private function inventoryView(): string
    {
        try {
            $inventory = Inventory::load($this->inventoryFile);
            $valid = '<p class="st-ok">'.self::e($this->t->t('web.inventory.valid', count($inventory->servers()), count($inventory->instances()))).'</p>';
        } catch (RuntimeException $e) {
            $valid = '<p class="st-crit">'.self::e($e->getMessage()).'</p>';
        }
        $content = is_file($this->inventoryFile) ? (string) file_get_contents($this->inventoryFile) : '';

        return $valid.'<pre>'.self::e($content).'</pre><p>'.self::e($this->t->t('web.inventory.edit')).'</p>';
    }

    /**
     * Start an action of the CLI in the background. A write needs the answer the console would
     * ask for (checked here, then again by the pilot through --confirm).
     *
     * @param array<string, mixed> $post
     */
    private function action(array $post): Response
    {
        $t = $this->t;
        $action = (string) ($post['action'] ?? '');
        $target = (string) ($post['target'] ?? '');
        if ( ! isset(self::ACTIONS[$action])) {
            return $this->plain(400, $t->t('web.error.action'));
        }
        $inventory = Inventory::load($this->inventoryFile);
        if ( ! isset($inventory->instances()[$target])) {
            return $this->plain(400, $t->t('instance.unknown', $target));
        }
        $answer = '';
        if (self::ACTIONS[$action]) {
            $answer = self::expectedAnswer($inventory->instance($target)->isProtected($inventory->protectedTags()), $target);
            if (trim((string) ($post['confirm'] ?? '')) !== $answer) {
                return $this->plain(409, $t->t('preview.refused'));
            }
        }
        $run = $this->actionCommand($action, $target, $answer);
        $task = date('YmdHis').'-'.bin2hex(random_bytes(2)).'-'.$action.'-'.$target;
        $base = self::tasksDir().'/'.$task;
        Paths::ensure(dirname($base));
        $meta = ['action' => $action, 'target' => $target, 'args' => $run['shown'], 'started_at' => time()];
        // Both written before the start: the operations page lists the task at once.
        foreach (['.json' => (string) json_encode($meta, JSON_UNESCAPED_SLASHES), '.log' => ''] as $suffix => $content) {
            if (false === file_put_contents($base.$suffix, $content)) {
                error_log("dolifleet serve: cannot write {$base}{$suffix}");

                return $this->plain(500, $t->t('web.error.start', "cannot write {$base}{$suffix}"));
            }
        }
        // The exit code lands next to the log: the operations page tells running, done, failed.
        $shell = implode(' ', array_map('escapeshellarg', $run['command'])).' > '.escapeshellarg($base.'.log').' 2>&1 < /dev/null; echo $? > '.escapeshellarg($base.'.exit');
        $exports = '';
        foreach ($run['env'] as $name => $value) {
            $exports .= $name.'='.escapeshellarg($value).' ';
        }
        $output = [];
        $code = 0;
        exec($exports.self::detacher().' sh -c '.escapeshellarg($shell).' > /dev/null 2>&1 < /dev/null &', $output, $code);
        if (0 !== $code) {
            error_log("dolifleet serve: cannot start {$task} (exit code {$code})");

            return $this->plain(500, $t->t('web.error.start', 'exit code '.$code));
        }

        return new Response(202, (string) json_encode(['task' => $task]), ['Content-Type' => 'application/json'] + $this->securityHeaders());
    }

    /**
     * setsid detaches the task from the server (Linux); nohup where it is missing (macOS).
     */
    private static function detacher(): string
    {
        static $detacher = null;
        if (null === $detacher) {
            $output = [];
            $code = 1;
            exec('command -v setsid 2>/dev/null', $output, $code);
            $detacher = 0 === $code ? 'setsid' : 'nohup';
        }

        return $detacher;
    }

    /**
     * Segments of a line of the console as HTML, each style as a class (st-crit, st-warn...):
     * the sign and the word carry the meaning, the color only underlines it.
     */
    private function line(Line $line): string
    {
        $html = '';
        foreach ($line->segments() as [$text, $style]) {
            $classes = array_map(static fn (string $s): string => 'st-'.$s, array_filter(explode(' ', $style), static fn (string $s): bool => 1 === preg_match('/^[a-z-]+$/', $s)));
            $html .= [] === $classes ? self::e($text) : '<span class="'.implode(' ', $classes).'">'.self::e($text).'</span>';
        }

        return $html;
    }

    /**
     * The command that produces the same screen, as on line 2 of the console.
     *
     * @param list<string> $args
     */
    private function commandLine(array $args): string
    {
        return '<p class="command" title="'.self::e($this->t->t('help.command')).'"><span class="prompt">$ </span><code>'.self::e(Command::line($args)).'</code></p>';
    }

    /**
     * @param list<string> $breadcrumb
     * @param list<string> $command
     */
    private function page(array $breadcrumb, bool $prod, string $counters, array $command, string $content): Response
    {
        $t = $this->t;
        $title = '<span class="crumb">dolifleet</span>';
        foreach ($breadcrumb as $part) {
            $title .= '<span class="sep"> &gt; </span>'.self::e($part);
        }
        if ($prod) {
            $title .= ' <span class="prod">PROD</span>';
        }
        $html = '<!DOCTYPE html><html lang="'.self::e($t->code()).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.self::e(implode(' > ', $breadcrumb)).' - dolifleet</title>'
            .'<meta name="dolifleet-token" content="'.self::e($this->csrf()).'"><link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script></head>'
            .'<body'.($prod ? ' class="prod"' : '').' data-failed="'.self::e($t->t('web.failed')).'"><header>'
            .'<nav><a href="/">'.self::e($t->t('web.nav.fleet')).'</a> <a href="/tasks">'.self::e($t->t('op.title')).'</a> <a href="/inventory" class="wide">'.self::e($t->t('web.nav.inventory')).'</a></nav>'
            .'<div class="title"><h1>'.$title.'</h1>'.('' !== $counters ? '<span class="counters">'.self::e($counters).'</span>' : '').'</div>'
            .$this->commandLine($command)
            .'</header><main>'.$content.'</main></body></html>';

        return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8'] + $this->securityHeaders());
    }

    private function asset(string $file, string $type): Response
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/web/'.$file);
        if (false === $content) {
            error_log("dolifleet serve: cannot read the asset {$file}");

            return $this->plain(500, $this->t->t('web.error.asset', $file));
        }

        return new Response(200, $content, ['Content-Type' => $type.'; charset=utf-8'] + $this->securityHeaders());
    }

    private function plain(int $status, string $message): Response
    {
        return new Response($status, $message."\n", ['Content-Type' => 'text/plain; charset=utf-8'] + $this->securityHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ];
    }
}
