<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Remote\SshTransport;

/**
 * An error, always in the same shape: what happened, why, what to do (exact lines, c copies
 * them), then the proof (last lines of the server). Enter checks again.
 */
final class ErrorScreen extends Screen
{
    private ?string $recheck = null;

    /**
     * @param list<string> $fix Exact lines to run
     * @param list<string> $proof Last lines written by the server
     * @param list<string>|null $recheckArgs Command run by Enter
     */
    public function __construct(
        private readonly string $instance,
        private readonly string $what,
        private readonly string $why,
        private readonly string $where,
        private readonly array $fix,
        private readonly array $proof,
        private readonly ?array $recheckArgs,
        private readonly bool $prod = false,
    ) {
    }

    /**
     * Error of an instance (refresh, list, command): explanation from the error code.
     */
    public static function forInstance(App $app, string $id, string $error, string $stderr): self
    {
        $t = $app->t;
        $code = (string) strtok($error, ':');
        $why = '';
        $where = '';
        $fix = [];
        try {
            $instance = $app->inventory()->instance($id);
        } catch (InventoryException) {
            $instance = null;
        }
        $server = null === $instance ? '?' : $instance->server;
        switch ($code) {
            case 'SSH_FAILED':
                $why = $t->t('error.ssh.why');
                $where = $t->t('error.ssh.where');
                $fix = ['ssh '.$server.' true'];
                break;
            case 'SUDO_FAILED':
                $php = '' !== (string) $instance?->php ? (string) $instance?->php : '/usr/bin/php';
                $user = (new SshTransport())->remoteUser($server);
                $runAs = '' !== (string) $instance?->runAs ? (string) $instance?->runAs : 'www-data';
                $why = $t->t('error.sudo.why', $php, $server);
                $where = $t->t('error.as_root_on', $server);
                $fix = [
                    "echo '".('' !== $user ? $user : 'debian')." ALL=({$runAs}) NOPASSWD: {$php}' > /etc/sudoers.d/dolifleet",
                    'chmod 0440 /etc/sudoers.d/dolifleet && visudo -c',
                ];
                break;
            case 'LOCKED':
            case 'PLAN_LOCKED':
                $why = $t->t('error.locked.why');
                $fix = ['dolifleet jobs '.$id];
                break;
            case 'DOLIBARR_LOAD_FAILED':
                $why = $t->t('error.database.why');
                $fix = ['dolifleet exec '.$id.' -- env doctor'];
                break;
            case 'NO_ENVELOPE':
            case 'PREMATURE_EXIT':
            case 'FATAL_ERROR':
            case 'INTERNAL_ERROR':
                $why = $t->t('error.dolibarr.why');
                $fix = ['dolifleet exec '.$id.' -- env doctor'];
                break;
            default:
                $fix = ['dolifleet check '.$id];
        }
        $proof = array_slice(array_values(array_filter(array_map('rtrim', explode("\n", $stderr)), static fn (string $l): bool => '' !== $l)), -3);

        return new self($id, $error, $why, $where, $fix, $proof, ['check', $id], $app->isProd($id));
    }

    /**
     * Error of a task of the console (usage, refused confirmation, failed write).
     */
    public static function forTask(App $app, Task $task): self
    {
        $instance = $task->instances[0] ?? '';
        if ('' !== $instance && 1 === count($task->instances)) {
            return self::forInstance($app, $instance, $task->error(), $task->stderr);
        }

        return new self($instance, $task->error(), '', '', [$task->commandLine()], $task->stderrTail(3), null);
    }

    public function breadcrumb(App $app): array
    {
        return array_values(array_filter(['' !== $this->instance ? $this->instance : null, $app->t->t('error.title')]));
    }

    public function command(App $app): ?array
    {
        return $this->recheckArgs;
    }

    public function prod(App $app): bool
    {
        return $this->prod;
    }

    public function keys(App $app): array
    {
        $keys = [];
        if ([] !== $this->fix) {
            $keys[] = ['c', $app->t->t('key.copy_fix')];
        }
        if (null !== $this->recheckArgs) {
            $keys[] = [$app->t->t('key.enter'), $app->t->t('key.recheck')];
        }
        $keys[] = ['!', $app->t->t('key.backstage')];
        $keys[] = [$app->t->t('key.esc'), $app->t->t('key.back')];

        return $keys;
    }

    public function handle(App $app, string $key): bool
    {
        if ('c' === $key && [] !== $this->fix) {
            $app->copy(implode("\n", $this->fix));

            return true;
        }
        if ('enter' === $key && null !== $this->recheckArgs && null === $this->recheck) {
            $this->recheck = $app->read($this->recheckArgs, 'recheck', '' !== $this->instance ? [$this->instance] : [])->id;

            return true;
        }

        return false;
    }

    public function taskFinished(App $app, Task $task): void
    {
        if ($task->id !== $this->recheck) {
            return;
        }
        $this->recheck = null;
        if (Task::DONE === $task->state()) {
            $app->toast($app->t->t('error.fixed'));
            if ('' !== $this->instance) {
                $app->refresh([$this->instance]);
            }
            if ($app->top() === $this) {
                $app->pop();
            }
        } else {
            $app->toast($app->t->t('error.still', $task->error()), 'error');
        }
    }

    public function body(App $app, int $width, int $height): array
    {
        $t = $app->t;
        $lines = [Line::blank(), Line::of('   '.$this->what, 'error')];
        if ('' !== $this->why) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   '.$t->t('error.why').' ', 'bold')->add($this->why);
        }
        if ([] !== $this->fix) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   '.$t->t('error.todo').('' !== $this->where ? ' '.$this->where : ''), 'bold');
            foreach ($this->fix as $line) {
                $lines[] = Line::of('     '.$line, 'command');
            }
        }
        if ([] !== $this->proof) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   '.$t->t('error.proof'), 'bold');
            foreach ($this->proof as $line) {
                $lines[] = Line::of('     '.$line, 'dim');
            }
        }
        if (null !== $this->recheck) {
            $lines[] = Line::blank();
            $lines[] = Line::of('   [..] '.$t->t('error.rechecking'), 'running');
        }

        return $lines;
    }
}
