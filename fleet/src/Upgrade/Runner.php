<?php

declare(strict_types=1);

namespace Dolifleet\Upgrade;

use Dolicli\Upgrade\FileSignature;
use Dolicli\Upgrade\MigrationPlanner;
use Dolicli\Upgrade\Releases;
use Dolifleet\Inventory\Instance;
use Dolifleet\Paths;
use Dolifleet\Remote\Dolicli;
use Dolifleet\Store\Journal;
use RuntimeException;

/**
 * Execution of an upgrade plan (section 7.4), step by step. The progress is saved locally and
 * on the server after each step, so the plan can be resumed by this pilot or another one
 * (dolifleet upgrade resume). After a failure the execution stops, the maintenance mode stays
 * on, install.lock is put back, and the recovery is shown.
 */
final class Runner
{
    public const PAUSED = 'paused';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** @var callable(string): void */
    private $say;

    /** @var callable(string): bool */
    private $confirm;

    /** @var array<string, mixed> */
    private array $plan;

    private string $holder;

    /**
     * @param array<string, mixed> $plan
     * @param callable(string): void $say
     * @param callable(string): bool $confirm Manual step done? (false: pause)
     * @param array{archive?: string, signature?: string} $sources Local archive and signature instead of downloading
     */
    public function __construct(
        private readonly Dolicli $dolicli,
        private readonly Instance $instance,
        array $plan,
        callable $say,
        callable $confirm,
        private readonly Journal $journal,
        private readonly array $sources = [],
    ) {
        $this->plan = $plan;
        $this->say = $say;
        $this->confirm = $confirm;
        $this->holder = get_current_user().'@'.gethostname().':'.$instance->id;
    }

    public static function planFile(string $instance): string
    {
        return Paths::dataDir().'/plans/'.$instance.'.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        return $this->plan;
    }

    /**
     * Run the steps not done yet. Returns done, paused or failed.
     */
    public function run(): string
    {
        if ([] !== ($this->plan['blockers'] ?? [])) {
            throw new RuntimeException("The plan has blockers:\n  ".implode("\n  ", (array) $this->plan['blockers']));
        }
        $this->journal->intention($this->runId(), $this->instance->id, 'upgrade run', ['to='.$this->plan['to']], []);
        foreach ($this->plan['steps'] as $step) {
            $id = (string) $step['id'];
            if (self::DONE === ($this->plan['progress'][$id]['state'] ?? '')) {
                continue;
            }
            ($this->say)("[{$id}] ".(string) $step['description']);
            try {
                $result = $this->step($step);
            } catch (RuntimeException $e) {
                $this->record($id, self::FAILED, $e->getMessage());
                $this->onFailure($id, $e->getMessage());
                $this->journal->result($this->runId(), $this->instance->id, 'upgrade run', 1, "{$id} failed: ".$e->getMessage());

                return self::FAILED;
            }
            if (self::PAUSED === $result) {
                $this->record($id, self::PAUSED, 'waiting for the administrator: dolifleet upgrade resume '.$this->instance->id);
                ($this->say)("Paused at {$id}: do it, then dolifleet upgrade resume {$this->instance->id}");

                return self::PAUSED;
            }
            $this->record($id, self::DONE, $result);
        }
        $this->journal->result($this->runId(), $this->instance->id, 'upgrade run', 0, 'upgraded to '.$this->plan['to']);

        return self::DONE;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function step(array $step): string
    {
        switch ($step['type']) {
            case 'lease':
                $this->call(['upgrade', 'lease', 'take', 'holder='.$this->holder, 'ttl=14400']);
                $this->call(['upgrade', 'plan', 'save', 'plan='.json_encode($this->plan, JSON_UNESCAPED_SLASHES)]);

                return 'lease taken by '.$this->holder;
            case 'maintenance':
                $this->call(array_merge(['system', 'maintenance', 'enable'], '' !== $this->instance->maintenanceLogin ? ['login='.$this->instance->maintenanceLogin] : []));

                return 'maintenance on';
            case 'manual':
                return ($this->confirm)((string) $step['description'].' Done?') ? 'confirmed by the administrator' : self::PAUSED;
            case 'modules-off':
                $off = [];
                foreach ((array) $step['modules'] as $module) {
                    $data = $this->call(['module', 'deactivate', 'name='.$module]);
                    if ( ! empty($data['changed'])) {
                        $off[] = $module;
                    }
                }
                $this->plan['deactivated_modules'] = $off;

                return [] === $off ? 'nothing to deactivate' : 'deactivated '.implode(', ', $off);
            case 'backup':
                $job = $this->job(['backup', '--database', '--documents', 'dir='.dirname($this->instance->htdocs)], $this->instance->php);

                return 'backup in '.dirname($this->instance->htdocs).' (job '.$job.')';
            case 'archive':
                return $this->sendArchive();
            case 'deploy':
                $this->call(['upgrade', 'deploy', 'version='.$this->plan['to'], 'archive='.$this->plan['remote_archive'], 'sha256='.$this->plan['archive_sha256'], 'signature='.$this->plan['remote_signature']]);

                return 'prepared';
            case 'switch-files':
                $status = $this->call(['upgrade', 'status']);
                if (MigrationPlanner::clean((string) $status['files_version']) === $this->plan['to']) {
                    return 'already switched';
                }
                $data = $this->call(['upgrade', 'switch-files', 'version='.$this->plan['to']]);
                $this->plan['previous_files'] = (string) ($data['previous'] ?? '');

                return 'previous files in '.$this->plan['previous_files'];
            case 'migration':
                $job = $this->job(['upgrade', 'migrate', 'from='.$this->plan['from']], (string) $step['php']);

                return 'migrated (job '.$job.')';
            case 'finalize':
                $this->call(['upgrade', 'finalize', 'from='.$this->plan['from']]);

                return 'install.lock in place';
            case 'verify':
                $status = $this->call(['upgrade', 'status']);
                if ( ! empty($status['upgrade_pending']) || MigrationPlanner::clean((string) $status['database_version']) !== $this->plan['to']) {
                    throw new RuntimeException('Check failed: database at '.$status['database_version'].', expected '.$this->plan['to']);
                }
                $this->call(['system', 'filecheck', 'xmlfile='.$this->plan['remote_signature']]);

                return 'database at '.$this->plan['to'].', files match the signature';
            case 'reopen':
                foreach ((array) ($this->plan['deactivated_modules'] ?? []) as $module) {
                    $this->call(['module', 'activate', 'name='.$module]);
                }
                if ( ! ($this->confirm)('Resume the scheduled jobs of Dolibarr on the server.')) {
                    return self::PAUSED;
                }
                $this->call(['system', 'maintenance', 'disable']);
                $this->call(['upgrade', 'lease', 'release', 'holder='.$this->holder]);

                return 'reopened';
        }
        throw new RuntimeException('Unknown step type '.(string) $step['type']);
    }

    /**
     * Archive and official signature: downloaded by the pilot (servers often have no outgoing
     * access), SHA256 noted, sent into a private temporary directory of the server.
     */
    private function sendArchive(): string
    {
        $to = (string) $this->plan['to'];
        $cache = Paths::dataDir().'/archives';
        Paths::ensure($cache);
        $archive = (string) ($this->sources['archive'] ?? '');
        if ('' === $archive) {
            $archive = $cache.'/dolibarr-'.$to.'.tgz';
            if ( ! is_file($archive)) {
                self::download(Releases::archiveUrls($to)[0], $archive);
            }
        }
        $signature = (string) ($this->sources['signature'] ?? '');
        if ('' === $signature) {
            $signature = $cache.'/filelist-'.$to.'.xml';
            if ( ! is_file($signature)) {
                self::download(sprintf(FileSignature::REMOTE_URL, $to), $signature);
            }
        }
        $sha = (string) hash_file('sha256', $archive);

        $transport = $this->dolicli->transport();
        $mktemp = $transport->run($this->instance->server, ['sh', '-c', 'umask 022; d=$(mktemp -d) && chmod 755 "$d" && echo "$d"'], '', 60);
        $dir = trim($mktemp->stdout);
        if (0 !== $mktemp->exitCode || 1 !== preg_match('#^/[A-Za-z0-9._/-]+$#', $dir)) {
            throw new RuntimeException('Cannot create a temporary directory on the server: '.trim($mktemp->stderr));
        }
        $extension = str_ends_with($archive, '.zip') ? 'zip' : 'tgz';
        foreach ([[$archive, $dir.'/dolibarr-'.$to.'.'.$extension], [$signature, $dir.'/filelist-'.$to.'.xml']] as [$local, $remote]) {
            $content = file_get_contents($local);
            if (false === $content) {
                throw new RuntimeException("Cannot read {$local}");
            }
            $copy = $transport->run($this->instance->server, ['sh', '-c', 'umask 022; cat > "$1"', 'sh', $remote], $content, 3600);
            if (0 !== $copy->exitCode) {
                throw new RuntimeException("Cannot send {$local}: ".trim($copy->stderr));
            }
        }
        $this->plan['remote_archive'] = $dir.'/dolibarr-'.$to.'.'.$extension;
        $this->plan['remote_signature'] = $dir.'/filelist-'.$to.'.xml';
        $this->plan['archive_sha256'] = $sha;

        return "archive (sha256 {$sha}) and signature sent to {$dir}";
    }

    private static function download(string $url, string $target): void
    {
        $context = stream_context_create(['http' => ['timeout' => 600, 'user_agent' => 'dolifleet', 'follow_location' => 1]]);
        $in = @fopen($url, 'rb', false, $context);
        if (false === $in) {
            throw new RuntimeException("Cannot download {$url}");
        }
        $out = fopen($target.'.part', 'wb');
        if (false === $out || false === stream_copy_to_stream($in, $out)) {
            throw new RuntimeException("Cannot write {$target}");
        }
        fclose($in);
        fclose($out);
        if ( ! rename($target.'.part', $target)) {
            throw new RuntimeException("Cannot write {$target}");
        }
    }

    /**
     * A long step, detached on the server; waits for its end.
     *
     * @param list<string> $arguments
     */
    private function job(array $arguments, string $php): string
    {
        $id = 'upgrade-'.$this->instance->id.'-'.date('YmdHis');
        $instance = '' !== $php ? new Instance($this->instance->id, $this->instance->server, $this->instance->htdocs, $this->instance->runAs, $php, $this->instance->webPhp, $this->instance->dolibarrUser, $this->instance->maintenanceLogin, $this->instance->tags, $this->instance->source) : $this->instance;
        $this->call(['job', 'start', 'id='.$id, 'args='.json_encode($this->dolicli->jobArguments($instance, array_merge($arguments, ['--yes'])))], $instance, ['body' => $this->dolicli->body()->content()]);
        while (true) {
            sleep(2);
            $status = $this->call(['job', 'status', 'id='.$id, 'lines=5'])['job'];
            foreach ((array) ($status['log'] ?? []) as $line) {
                ($this->say)('  '.(string) $line);
            }
            if ('done' === $status['state']) {
                return $id;
            }
            if ('failed' === $status['state']) {
                $error = (string) ($status['result']['errors'][0]['message'] ?? 'exit code '.($status['exit_code'] ?? '?'));
                throw new RuntimeException("job {$id} failed: {$error}");
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $files
     * @return array<string, mixed>
     */
    private function call(array $arguments, ?Instance $instance = null, array $files = []): array
    {
        $outcome = $this->dolicli->run($instance ?? $this->instance, array_merge($arguments, ['--yes']), 600, $files);
        if ( ! $outcome->ok()) {
            throw new RuntimeException(implode(' ', array_slice($arguments, 0, 3)).': '.$outcome->error);
        }

        return $outcome->data();
    }

    /**
     * install.lock is always put back; maintenance stays on; recovery shown.
     */
    private function onFailure(string $step, string $error): void
    {
        $done = fn (string $id): bool => self::DONE === ($this->plan['progress'][$id]['state'] ?? '');
        if ($done('switch-files') && 'finalize' !== $step) {
            $finalize = $this->dolicli->run($this->instance, ['upgrade', 'finalize', 'from='.$this->plan['from'], '--yes']);
            ($this->say)('Finalization after the failure: '.($finalize->ok() ? 'install.lock in place' : $finalize->error));
        }
        $recovery = ["Step {$step} failed: {$error}", 'The maintenance mode stays on.'];
        if ($done('backup')) {
            $recovery[] = 'Database backup in '.dirname($this->instance->htdocs).' (dolibarr dump of the backup step).';
        }
        if ($done('switch-files')) {
            $recovery[] = 'Previous files: '.($this->plan['previous_files'] ?? 'htdocs.old-<date>').', put back with: dolifleet exec '.$this->instance->id.' -- upgrade restore-files';
            $recovery[] = 'The database may be partly migrated: restore the backup before using the previous files.';
        }
        $recovery[] = 'After a fix: dolifleet upgrade resume '.$this->instance->id;
        $this->plan['recovery'] = $recovery;
        $this->save();
        foreach ($recovery as $line) {
            ($this->say)($line);
        }
    }

    private function record(string $step, string $state, string $message): void
    {
        $this->plan['progress'][$step] = ['state' => $state, 'at' => date('c'), 'message' => $message];
        $this->save();
        if ('lease' !== $step || self::DONE === $state) {
            $this->dolicli->run($this->instance, ['upgrade', 'plan', 'save', 'plan='.json_encode($this->plan, JSON_UNESCAPED_SLASHES), '--yes']);
        }
    }

    private function save(): void
    {
        $file = self::planFile($this->instance->id);
        Paths::ensure(dirname($file));
        if (false === file_put_contents($file, json_encode($this->plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")) {
            throw new RuntimeException("Cannot write {$file}");
        }
    }

    private function runId(): string
    {
        return 'upgrade-'.$this->instance->id.'-'.mb_substr((string) $this->plan['created_at'], 0, 19);
    }
}
