<?php

namespace Dolicli\Job;

use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;
use Dolicli\System\Files;
use Dolicli\System\Log;
use Dolicli\System\Process;

/**
 * Long operations detached from the SSH session (a cut would send SIGHUP to PHP in the middle
 * of a migration): DOL_DATA_ROOT/dolicli/jobs/<id>/ holds a copy of the body, the arguments,
 * a runner started with setsid, its log, its result (envelope) and its status.
 */
final class Jobs
{
    const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    const RUNNER = <<<'PHP'
<?php
// Runner of a dolicli job: runs the body with the arguments of args.json, writes result.json,
// log and status.json. Written by dolicli job start.
$dir = dirname(__FILE__);
$status = function ($values) use ($dir) {
    $current = json_decode((string) @file_get_contents($dir . '/status.json'), true);
    $current = is_array($current) ? $current : array();
    file_put_contents($dir . '/status.json.tmp', json_encode(array_merge($current, $values)));
    rename($dir . '/status.json.tmp', $dir . '/status.json');
};
$status(array('state' => 'running', 'pid' => getmypid(), 'started_at' => date('c')));
$args = json_decode((string) file_get_contents($dir . '/args.json'), true);
$stream = '<?php namespace { $DOLICLI_ARGV = ' . var_export(array_merge(array('dolicli'), is_array($args) ? $args : array()), true) . '; } ?>'
    . file_get_contents($dir . '/dolicli.php');
$command = version_compare(PHP_VERSION, '7.4.0', '>=') ? array(PHP_BINARY, '--') : 'exec ' . escapeshellarg(PHP_BINARY) . ' --';
$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/result.json', 'w'), 2 => array('file', $dir . '/log', 'a')), $pipes);
if (!is_resource($process)) {
    $status(array('state' => 'failed', 'exit_code' => -1, 'ended_at' => date('c'), 'error' => 'cannot start PHP'));
    exit(1);
}
fwrite($pipes[0], $stream);
fclose($pipes[0]);
$code = proc_close($process);
$status(array('state' => 0 === $code ? 'done' : 'failed', 'exit_code' => $code, 'ended_at' => date('c')));

PHP;

    /** @var string */
    private $dir;

    public function __construct(string $dataRoot)
    {
        $this->dir = rtrim($dataRoot, '/') . '/dolicli/jobs';
    }

    public function dir(string $id): string
    {
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            throw Failure::usage('INVALID_VALUE', "Invalid job identifier '{$id}'.");
        }

        return $this->dir . '/' . $id;
    }

    /**
     * @param array<int, string> $arguments dolicli arguments of the job
     * @return array<string, mixed>
     */
    public function start(string $id, array $arguments, string $body): array
    {
        $dir = $this->dir($id);
        if (file_exists($dir)) {
            throw Failure::operation('JOB_EXISTS', "Job {$id} already exists.");
        }
        Files::ensureDir($dir, 0700);
        Files::writeAtomic($dir . '/dolicli.php', $body, 0600);
        Files::writeAtomic($dir . '/args.json', Json::encode(array_values($arguments), false), 0600);
        Files::writeAtomic($dir . '/run.php', self::RUNNER, 0600);
        Files::writeAtomic($dir . '/status.json', Json::encode(['id' => $id, 'state' => 'starting', 'command' => implode(' ', array_slice($arguments, 0, 3)), 'created_at' => date('c')], false), 0600);

        $setsid = Process::find(['setsid']);
        $launch = ('' !== $setsid ? escapeshellarg($setsid) . ' ' : 'nohup ') . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/run.php')
            . ' < /dev/null > /dev/null 2>&1 &';
        $output = [];
        $code = 0;
        exec($launch, $output, $code);
        if (0 !== $code) {
            Log::error("job {$id}: cannot start ({$launch})");
            throw Failure::operation('JOB_START_FAILED', "Cannot start job {$id}.");
        }

        return $this->status($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $id, int $logLines = 50): array
    {
        $dir = $this->dir($id);
        $status = Json::decode((string) @file_get_contents($dir . '/status.json'));
        if (!is_array($status)) {
            throw Failure::operation('JOB_NOT_FOUND', "Job {$id} not found.");
        }
        $status['id'] = $id;
        $log = @file($dir . '/log', FILE_IGNORE_NEW_LINES);
        $status['log'] = is_array($log) ? array_slice($log, -$logLines) : [];
        if (in_array(isset($status['state']) ? $status['state'] : '', ['done', 'failed'], true)) {
            $result = Json::decode((string) @file_get_contents($dir . '/result.json'));
            $status['result'] = is_array($result) ? $result : null;
        }

        return $status;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $jobs = [];
        $entries = @scandir($this->dir);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (1 === preg_match(self::ID_PATTERN, $entry) && is_dir($this->dir . '/' . $entry)) {
                $status = $this->status($entry, 0);
                unset($status['log'], $status['result']);
                $jobs[] = $status;
            }
        }

        return $jobs;
    }
}
