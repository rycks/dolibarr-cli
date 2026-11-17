<?php

declare(strict_types=1);

namespace Dolifleet\Web;

use Dolifleet\Inventory\Inventory;
use Dolifleet\Paths;
use Dolifleet\Store\Journal;
use Dolifleet\Store\StateStore;
use RuntimeException;

/**
 * Local dashboard of dolifleet serve (section 6.7), served by the built-in server of PHP on
 * 127.0.0.1 only.
 *
 * - The token shown in the terminal is exchanged on the first request for an HttpOnly,
 *   SameSite=Strict cookie, then the browser is redirected to a URL without it.
 * - Host and Origin are checked (DNS rebinding); actions are POST with the token in a header.
 * - Data comes from the servers and is untrusted: everything is escaped, the page sets a strict
 *   CSP and loads nothing external.
 * - The built-in server handles one request at a time: actions start the CLI in the background
 *   and are followed in the list of tasks. No action exists only here.
 */
final class Dashboard
{
    public const COOKIE = 'dolifleet';

    /** action => dolifleet arguments (target appended). */
    public const ACTIONS = [
        'maintenance-on' => ['maintenance', 'on'],
        'maintenance-off' => ['maintenance', 'off'],
        'collect' => ['status', '--refresh'],
        'backup' => ['backup', '--database', '--documents'],
    ];

    public function __construct(
        private readonly string $token,
        private readonly int $port,
        private readonly string $inventoryFile,
        private readonly string $dolifleet,
    ) {
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
            return $this->plain(421, 'Unknown host');
        }
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        if ('' !== $origin && ! in_array($origin, ['http://127.0.0.1:'.$this->port, 'http://localhost:'.$this->port], true)) {
            return $this->plain(403, 'Cross-origin request refused');
        }
        $path = (string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');

        if (isset($query['token'])) {
            if ( ! hash_equals($this->token, (string) $query['token'])) {
                return $this->plain(403, 'Invalid token');
            }

            return new Response(303, '', [
                'Location' => '/',
                'Set-Cookie' => self::COOKIE.'='.$this->session().'; Path=/; HttpOnly; SameSite=Strict',
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }
        if ( ! hash_equals($this->session(), (string) ($cookies[self::COOKIE] ?? ''))) {
            return $this->plain(401, 'Open the URL shown by dolifleet serve in the terminal.');
        }

        if ('/app.js' === $path) {
            return $this->asset('app.js', 'text/javascript');
        }
        if ('/app.css' === $path) {
            return $this->asset('app.css', 'text/css');
        }
        if ('POST' === $method) {
            if ( ! hash_equals($this->csrf(), (string) ($server['HTTP_X_DOLIFLEET_TOKEN'] ?? ''))) {
                return $this->plain(403, 'Missing or invalid action token');
            }
            if ('/action' === $path) {
                return $this->action($post);
            }

            return $this->plain(404, 'Not found');
        }
        if ('/' === $path) {
            return $this->page('Fleet', $this->fleet());
        }
        if (1 === preg_match('#^/instance/([a-z0-9][a-z0-9_-]*)$#', $path, $m)) {
            return $this->page('Instance '.$m[1], $this->instance($m[1]));
        }
        if ('/tasks' === $path) {
            return $this->page('Tasks', $this->tasks());
        }
        if ('/inventory' === $path) {
            return $this->page('Inventory', $this->inventoryView());
        }

        return $this->plain(404, 'Not found');
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

    private function fleet(): string
    {
        $inventory = Inventory::load($this->inventoryFile);
        $store = new StateStore();
        $rows = '';
        foreach ($inventory->instances() as $id => $instance) {
            $last = $store->last($id);
            $document = $last['document'] ?? [];
            $checks = (array) ($document['checks'] ?? []);
            $status = (string) ($document['status'] ?? 'unknown');
            $age = null === $last ? 'never' : (time() - (int) ($document['generated_at_unix'] ?? $last['received_at'])).' s';
            $rows .= '<tr class="status-'.self::e($status).'"><td><a href="/instance/'.self::e($id).'">'.self::e($id).'</a>'
                .($instance->isProtected($inventory->protectedTags()) ? ' <span class="protected">protected</span>' : '').'</td>'
                .'<td>'.self::e($status).'</td>'
                .'<td>'.self::e($checks['dolibarr.version']['value'] ?? '').'</td>'
                .'<td>'.self::e(isset($checks['maintenance.enabled']['value']) ? ($checks['maintenance.enabled']['value'] ? 'on' : 'off') : '').'</td>'
                .'<td>'.self::e($checks['sessions.count']['value'] ?? '').'</td>'
                .'<td>'.self::e(isset($checks['backup.age']['value']) ? round((int) $checks['backup.age']['value'] / 86400, 1).' d' : '').'</td>'
                .'<td>'.self::e(isset($checks['filecheck.modified']['value']) ? $checks['filecheck.modified']['value'].' modified' : '').'</td>'
                .'<td>'.self::e($age).'</td>'
                .'<td>'.$this->buttons($id, $instance->isProtected($inventory->protectedTags())).'</td></tr>';
        }

        return '<table><thead><tr><th>Instance</th><th>Status</th><th>Version</th><th>Maintenance</th><th>Sessions</th><th>Backup age</th>'
            .'<th>Filecheck</th><th>Data age</th><th>Actions</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    private function buttons(string $id, bool $protected): string
    {
        $out = '';
        foreach (array_keys(self::ACTIONS) as $action) {
            $out .= '<button type="button" class="action" data-action="'.self::e($action).'" data-target="'.self::e($id).'" data-protected="'.($protected ? '1' : '0').'">'.self::e($action).'</button> ';
        }

        return $out;
    }

    private function instance(string $id): string
    {
        $inventory = Inventory::load($this->inventoryFile);
        $instance = $inventory->instance($id);
        $store = new StateStore();
        $last = $store->last($id);
        $rows = '';
        foreach ((array) ($last['document']['checks'] ?? []) as $check => $result) {
            $rows .= '<tr class="status-'.self::e($result['status'] ?? '').'"><td>'.self::e($check).'</td><td>'.self::e($result['status'] ?? '').'</td><td>'
                .self::e($result['value'] ?? '').'</td><td>'.self::e($result['message'] ?? '').'</td><td>'.self::e($result['collected_at'] ?? '').'</td></tr>';
        }
        $history = '';
        foreach ($store->history($id, 20) as $entry) {
            $history .= '<li>'.self::e(date('c', $entry['received_at'])).': '.self::e($entry['status']).'</li>';
        }
        $journal = '';
        foreach ((new Journal())->tail(20, $id) as $entry) {
            $journal .= '<li>'.self::e($entry['date'] ?? '').' '.self::e($entry['phase'] ?? '').' '.self::e($entry['command'] ?? '').' '.self::e($entry['summary'] ?? '').'</li>';
        }

        return '<p>'.self::e($instance->server).': '.self::e($instance->htdocs).'</p>'.$this->buttons($id, $instance->isProtected($inventory->protectedTags()))
            .'<h2>Checks</h2><table><thead><tr><th>Check</th><th>Status</th><th>Value</th><th>Message</th><th>Collected</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<h2>History</h2><ul>'.$history.'</ul><h2>Journal</h2><ul>'.$journal.'</ul>';
    }

    private function tasks(): string
    {
        $out = '';
        foreach (array_reverse(glob(self::tasksDir().'/*.log') ?: []) as $file) {
            $content = (string) file_get_contents($file);
            $out .= '<h2>'.self::e(basename($file, '.log')).'</h2><pre>'.self::e(mb_substr($content, -4000)).'</pre>';
        }

        return '' === $out ? '<p>No task.</p>' : $out;
    }

    private function inventoryView(): string
    {
        try {
            $inventory = Inventory::load($this->inventoryFile);
            $valid = '<p class="status-ok">Inventory valid: '.count($inventory->servers()).' servers, '.count($inventory->instances()).' instances.</p>';
        } catch (RuntimeException $e) {
            $valid = '<p class="status-critical">'.self::e($e->getMessage()).'</p>';
        }

        return $valid.'<pre>'.self::e((string) file_get_contents($this->inventoryFile)).'</pre><p>Editing: change the YAML files, then reload this page.</p>';
    }

    /**
     * Start an action of the CLI in the background, with the same guards: a protected instance
     * needs its name typed again in the page.
     *
     * @param array<string, mixed> $post
     */
    private function action(array $post): Response
    {
        $action = (string) ($post['action'] ?? '');
        $target = (string) ($post['target'] ?? '');
        if ( ! isset(self::ACTIONS[$action])) {
            return $this->plain(400, 'Unknown action');
        }
        $inventory = Inventory::load($this->inventoryFile);
        if ( ! isset($inventory->instances()[$target])) {
            return $this->plain(400, 'Unknown instance');
        }
        $instance = $inventory->instance($target);
        $write = 'collect' !== $action;
        if ($write && $instance->isProtected($inventory->protectedTags()) && ($post['confirm'] ?? '') !== $target) {
            return $this->plain(409, 'Protected instance: type its name to confirm.');
        }
        $arguments = array_merge(self::ACTIONS[$action], [$target], $write ? ['--yes'] : []);
        $task = date('YmdHis').'-'.$action.'-'.$target;
        $log = self::tasksDir().'/'.$task.'.log';
        Paths::ensure(dirname($log));
        $command = 'setsid '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->dolifleet).' --no-interaction '.implode(' ', array_map('escapeshellarg', $arguments))
            .' > '.escapeshellarg($log).' 2>&1 < /dev/null &';
        exec($command, $output, $code);
        if (0 !== $code) {
            return $this->plain(500, 'Cannot start the task');
        }

        return new Response(202, (string) json_encode(['task' => $task]), ['Content-Type' => 'application/json'] + $this->securityHeaders());
    }

    public static function tasksDir(): string
    {
        return Paths::dataDir().'/serve-tasks';
    }

    private function page(string $title, string $content): Response
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'.self::e($title).' - dolifleet</title>'
            .'<meta name="dolifleet-token" content="'.self::e($this->csrf()).'"><link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script></head>'
            .'<body><nav><a href="/">Fleet</a> <a href="/tasks">Tasks</a> <a href="/inventory">Inventory</a></nav><h1>'.self::e($title).'</h1>'.$content.'</body></html>';

        return new Response(200, $html, ['Content-Type' => 'text/html; charset=utf-8'] + $this->securityHeaders());
    }

    private function asset(string $file, string $type): Response
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/web/'.$file);

        return new Response(200, false === $content ? '' : $content, ['Content-Type' => $type.'; charset=utf-8'] + $this->securityHeaders());
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
