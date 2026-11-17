<?php

declare(strict_types=1);

namespace Dolifleet\Console;

use Dolifleet\Console\Tasks\Command;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;

/**
 * Operations of the session, followed through the same dolifleet commands an administrator
 * would type (jobs, upgrade status). They run on the servers or detached: closing the console
 * or leaving a screen never stops them.
 */
final class Operations
{
    public const POLL_SECONDS = 2.0;

    /** @var list<Operation> */
    private array $operations = [];

    private int $sequence = 0;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param list<string> $instances
     * @param array<string, mixed> $meta
     */
    public function create(string $kind, string $title, array $instances, array $meta = []): Operation
    {
        $operation = new Operation('op-'.date('Ymd-His').'-'.++$this->sequence, $kind, $title, $instances, microtime(true), $meta);
        foreach ($instances as $id) {
            $operation->steps[] = ['state' => 'pending', 'label' => $id, 'detail' => ''];
        }
        $this->operations[] = $operation;

        return $operation;
    }

    /**
     * Starts the write of an operation and links its task to it.
     *
     * @param list<string> $args
     * @param list<string> $options
     */
    public function run(Operation $operation, array $args, array $options, string $purpose): Task
    {
        $task = $this->app->write($args, $options, $purpose, $operation->instances, ['operation' => $operation->id]);
        $operation->tasks[] = $task->id;
        if ($task->finished()) {
            $this->taskFinished($task);
        }

        return $task;
    }

    /**
     * Upgrade paused at a manual step: the administrator says it is done, the run goes on.
     */
    public function resume(Operation $operation): void
    {
        $instance = $operation->instances[0];
        $operation->state = Operation::RUNNING;
        $options = ['--confirm='.$instance];
        if ('' !== $operation->pausedStep) {
            $options[] = '--done='.$operation->pausedStep;
        }
        $operation->pausedStep = '';
        $this->run($operation, ['upgrade', 'resume', $instance], $options, 'upgrade-run');
    }

    public function find(string $id): ?Operation
    {
        foreach ($this->operations as $operation) {
            if ($operation->id === $id) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Most recent first.
     *
     * @return list<Operation>
     */
    public function all(): array
    {
        return array_reverse($this->operations);
    }

    public function taskFinished(Task $task): void
    {
        $operation = $this->find((string) ($task->meta['operation'] ?? ''));
        if (null === $operation) {
            return;
        }
        $operation->polling = false;
        switch ($task->purpose) {
            case 'backup':
                $this->backupStarted($operation, $task);
                break;
            case 'backup-follow':
                $this->backupFollowed($operation, $task);
                break;
            case 'upgrade-run':
                $this->upgradeRun($operation, $task);
                break;
            case 'upgrade-status':
                $this->upgradeProgress($operation, (array) ($task->json['local_plan'] ?? []));
                break;
            default:
                $this->written($operation, $task);
        }
    }

    /**
     * Follows the running operations: jobs of the backups, steps of the upgrades.
     */
    public function tick(): void
    {
        $now = microtime(true);
        foreach ($this->operations as $operation) {
            if (Operation::RUNNING !== $operation->state || $operation->polling || $now < $operation->nextPoll) {
                continue;
            }
            if ('backup' === $operation->kind && [] !== $operation->jobs) {
                foreach ($operation->jobs as $instance => $job) {
                    if ('running' === ($operation->jobStates[$instance] ?? 'running')) {
                        $this->app->read(Command::jobs($instance, $job), 'backup-follow', [$instance], ['operation' => $operation->id]);
                        $operation->polling = true;
                    }
                }
            } elseif ('upgrade' === $operation->kind && [] !== $operation->tasks) {
                $this->app->read(Command::upgradeStatus($operation->instances[0]), 'upgrade-status', $operation->instances, ['operation' => $operation->id]);
                $operation->polling = true;
            }
            $operation->nextPoll = $now + self::POLL_SECONDS;
        }
    }

    /**
     * Band of the operations: the running ones, and those finished less than a minute ago.
     *
     * @return list<Line>
     */
    public function band(int $cols): array
    {
        $lines = [];
        foreach ($this->all() as $operation) {
            if ($operation->finished() && null !== $operation->endedAt && microtime(true) - $operation->endedAt > 60) {
                continue;
            }
            $state = $this->app->t->t('op.state.'.$operation->state);
            $style = match ($operation->state) {
                Operation::FAILED => 'crit',
                Operation::PAUSED => 'warn',
                Operation::RUNNING => 'running',
                default => '',
            };
            $line = Line::of(' '.$this->app->t->t('op.band'), 'dim')->add('  '.$operation->title, $style)
                ->add('  '.$state.('' !== $operation->progress() ? ' '.$operation->progress() : ''), $style);
            $hint = ' j '.$this->app->t->t('op.follow').' ';
            $lines[] = $line->fit($cols - Line::widthOf($hint))->add($hint, 'key');
            if (2 === count($lines)) {
                break;
            }
        }

        return $lines;
    }

    private function written(Operation $operation, Task $task): void
    {
        foreach ($operation->steps as $index => $step) {
            $outcome = $task->outcome($step['label']);
            if ([] === $outcome) {
                $operation->steps[$index]['state'] = Task::DONE === $task->state() ? 'done' : 'skipped';
                continue;
            }
            $operation->steps[$index]['state'] = ! empty($outcome['ok']) ? 'done' : 'failed';
            $operation->steps[$index]['detail'] = (string) ($outcome['error'] ?? '');
        }
        $this->finish($operation, $task);
        $this->app->refresh($operation->instances);
    }

    private function backupStarted(Operation $operation, Task $task): void
    {
        foreach ($operation->steps as $index => $step) {
            $id = $step['label'];
            $outcome = $task->outcome($id);
            $job = (string) ($task->data($id)['id'] ?? '');
            if ( ! empty($outcome['ok']) && '' !== $job) {
                $operation->jobs[$id] = $job;
                $operation->jobStates[$id] = 'running';
                $operation->steps[$index]['state'] = 'running';
                $operation->steps[$index]['detail'] = $this->app->t->t('op.job', $job);
            } else {
                $operation->steps[$index]['state'] = [] === $outcome ? 'skipped' : 'failed';
                $operation->steps[$index]['detail'] = (string) ($outcome['error'] ?? '');
            }
        }
        if ([] === $operation->jobs) {
            $this->finish($operation, $task);
        }
    }

    private function backupFollowed(Operation $operation, Task $task): void
    {
        $instance = $task->instances[0] ?? '';
        $job = (array) ($task->data($instance)['job'] ?? []);
        $state = (string) ($job['state'] ?? '');
        if (in_array($state, ['done', 'failed'], true)) {
            $ok = 'done' === $state && true === ($job['result']['ok'] ?? false);
            $operation->jobStates[$instance] = $ok ? 'done' : 'failed';
            foreach ($operation->steps as $index => $step) {
                if ($step['label'] === $instance) {
                    $operation->steps[$index]['state'] = $ok ? 'done' : 'failed';
                    $operation->steps[$index]['detail'] = $ok ? $operation->steps[$index]['detail'] : (string) ($job['result']['errors'][0]['message'] ?? $this->app->t->t('op.job_failed'));
                }
            }
        }
        if ( ! in_array('running', $operation->jobStates, true)) {
            $failed = in_array('failed', $operation->jobStates, true);
            $operation->state = $failed ? Operation::FAILED : Operation::DONE;
            $operation->endedAt = microtime(true);
            $this->app->refresh($operation->instances);
            $this->app->toast($this->app->t->t($failed ? 'op.finished_failed' : 'op.finished_ok', $operation->title), $failed ? 'error' : '');
        }
    }

    private function upgradeRun(Operation $operation, Task $task): void
    {
        $this->upgradeProgress($operation, (array) ($task->json['plan'] ?? []));
        $state = (string) ($task->json['state'] ?? '');
        if ('paused' === $state) {
            $operation->state = Operation::PAUSED;
            foreach ((array) ($task->json['plan']['progress'] ?? []) as $step => $progress) {
                if ('paused' === ($progress['state'] ?? '')) {
                    $operation->pausedStep = (string) $step;
                }
            }
            $this->app->toast($this->app->t->t('op.paused', $operation->title), 'warn');

            return;
        }
        $this->finish($operation, $task, 'done' === $state);
        $this->app->refresh($operation->instances);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function upgradeProgress(Operation $operation, array $plan): void
    {
        if ( ! isset($plan['steps']) || ! is_array($plan['steps'])) {
            return;
        }
        $steps = [];
        foreach ($plan['steps'] as $step) {
            $id = (string) ($step['id'] ?? '');
            $progress = (array) ($plan['progress'][$id] ?? []);
            $label = $this->app->t->has('upgrade.step.'.$id) ? $this->app->t->t('upgrade.step.'.$id) : (string) ($step['description'] ?? $id);
            $steps[] = ['state' => (string) ($progress['state'] ?? 'pending'), 'label' => $label, 'detail' => (string) ($progress['message'] ?? '')];
        }
        $operation->steps = $steps;
    }

    private function finish(Operation $operation, Task $task, ?bool $ok = null): void
    {
        $ok ??= Task::DONE === $task->state() && ! in_array('failed', array_column($operation->steps, 'state'), true);
        $operation->state = $ok ? Operation::DONE : Operation::FAILED;
        $operation->error = $ok ? '' : $task->error();
        $operation->endedAt = microtime(true);
        $this->app->toast($this->app->t->t($ok ? 'op.finished_ok' : 'op.finished_failed', $operation->title), $ok ? '' : 'error');
    }
}
