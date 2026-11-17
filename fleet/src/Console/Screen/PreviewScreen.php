<?php

declare(strict_types=1);

namespace Dolifleet\Console\Screen;

use Dolifleet\Console\Action;
use Dolifleet\Console\App;
use Dolifleet\Console\Tasks\Task;
use Dolifleet\Console\Terminal\Line;
use Dolifleet\Console\View\Input;
use Dolifleet\Console\View\Signs;

/**
 * Before any write: the exact command, what dolicli will do (its own simulation, or the plan of
 * an upgrade), the instances touched, whether it can be undone, then the confirmation:
 * Enter outside production, the name of the instance in production, the number of production
 * instances for a batch that contains some. Nothing starts on a single key.
 */
final class PreviewScreen extends Screen
{
    private ?string $simulation = null;

    private ?Task $result = null;

    private Input $answer;

    private string $refusal = '';

    /** What must be typed to confirm, null when Enter is enough (computed when the screen opens). */
    private ?string $expected = null;

    public function __construct(public readonly Action $action)
    {
        $this->answer = new Input('', 64);
    }

    public function breadcrumb(App $app): array
    {
        return [1 === count($this->action->instances) ? $this->action->instances[0] : $app->t->t('preview.instances', count($this->action->instances)), $this->action->title];
    }

    public function command(App $app): array
    {
        return $this->action->args;
    }

    public function counters(App $app): string
    {
        $prod = count($this->protected($app));

        return $prod > 0 ? $app->t->t('preview.prod_count', $prod) : '';
    }

    public function prod(App $app): bool
    {
        return [] !== $this->protected($app);
    }

    public function captures(): bool
    {
        return $this->needsTyping();
    }

    public function keys(App $app): array
    {
        $keys = [];
        if ($this->ready() && ! $this->needsTyping()) {
            $keys[] = [$app->t->t('key.enter'), $app->t->t('key.run')];
        }
        $keys[] = [$app->t->t('key.esc'), $app->t->t('key.cancel')];
        if ( ! $this->needsTyping()) {
            $keys[] = ['y', $app->t->t('key.copy')];
        }

        return $keys;
    }

    public function handle(App $app, string $key): bool
    {
        $this->start($app);
        if ('esc' === $key) {
            $app->pop();

            return true;
        }
        if ($this->needsTyping() && 'enter' !== $key) {
            $this->answer->handle($key);
            $this->refusal = '';

            return true;
        }
        if ('enter' === $key) {
            if ( ! $this->ready()) {
                return true;
            }
            if (null !== $this->expected && trim($this->answer->value) !== $this->expected) {
                $this->refusal = $app->t->t('preview.refused');
                $this->answer->value = '';

                return true;
            }
            $this->execute($app);

            return true;
        }

        return false;
    }

    public function taskFinished(App $app, Task $task): void
    {
        if ($task->id === $this->simulation) {
            $this->result = $task;
        }
    }

    public function body(App $app, int $width, int $height): array
    {
        $this->start($app);
        $t = $app->t;
        $lines = [Line::of(' '.$this->action->summary, 'bold'), Line::blank()];
        $lines = array_merge($lines, $this->instancesLines($app));
        $lines[] = Line::blank();

        if (null === $this->result) {
            $lines[] = Line::of('   ')->append(Signs::step('running'))->add(' '.$t->t('preview.simulating'), 'running');
        } elseif ('upgrade' === $this->action->kind) {
            $lines = array_merge($lines, $this->planLines($app));
        } else {
            $lines = array_merge($lines, $this->simulationLines($app));
        }
        $lines[] = Line::blank();
        $lines[] = Line::of('   '.$t->t('preview.undo').' ', 'bold')->add($this->action->undo);
        $lines[] = Line::blank();
        if ('' !== $this->refusal) {
            $lines[] = Line::of('   '.$this->refusal, 'error');
        }
        if ($this->ready()) {
            $question = $this->question($app);
            if (null !== $question) {
                $lines[] = Line::of('   '.$question.' ', 'bold')->add($this->answer->value, 'input')->add(' ', 'reverse');
            } else {
                $lines[] = Line::of('   '.$t->t('preview.press_enter'), 'bold');
            }
        } elseif (null !== $this->result) {
            $lines[] = Line::of('   '.$t->t('preview.cannot_run'), 'error');
        }

        if (count($lines) > $height) {
            // Keep the question visible: the middle of a long simulation is cut.
            $tail = array_slice($lines, -6);
            $lines = array_merge(array_slice($lines, 0, $height - 7), [Line::of('   ...', 'dim')], $tail);
        }

        return $lines;
    }

    /**
     * Production instances touched.
     *
     * @return list<string>
     */
    private function protected(App $app): array
    {
        return array_values(array_filter($this->action->instances, static fn (string $id): bool => $app->isProd($id)));
    }

    private function start(App $app): void
    {
        if (null !== $this->simulation) {
            return;
        }
        $this->expected = $this->expectedFor($app);
        if (null === $this->action->simulation) {
            $this->simulation = '';

            return;
        }
        $options = 'upgrade' === $this->action->kind ? [] : ['--dry-run'];
        $task = $app->backend->start($this->action->simulation, 'simulation', $this->action->instances, false, $options);
        $this->simulation = $task->id;
    }

    /**
     * Simulation done and successful (plan without blocker for an upgrade).
     */
    private function ready(): bool
    {
        if (null === $this->action->simulation) {
            return true;
        }
        if (null === $this->result || Task::DONE !== $this->result->state()) {
            return false;
        }
        if ('upgrade' === $this->action->kind) {
            return [] === (array) ($this->result->json['blockers'] ?? []);
        }

        return true;
    }

    private function needsTyping(): bool
    {
        return $this->ready() && null !== $this->expected;
    }

    /**
     * What must be typed, null when Enter is enough.
     */
    private function expectedFor(App $app): ?string
    {
        $protected = $this->protected($app);
        if ($this->action->destructive || (1 === count($this->action->instances) && [] !== $protected)) {
            return $this->action->instances[0];
        }
        if ([] !== $protected) {
            return (string) count($protected);
        }

        return null;
    }

    private function question(App $app): ?string
    {
        $expected = $this->expected;
        if (null === $expected) {
            return null;
        }
        if ($expected === $this->action->instances[0] && (1 === count($this->action->instances) || $this->action->destructive)) {
            return $app->t->t('preview.type_name', $expected);
        }

        return $app->t->t('preview.type_count');
    }

    /**
     * @return list<Line>
     */
    private function instancesLines(App $app): array
    {
        $t = $app->t;
        $names = Line::of('   '.$t->t('preview.on').' ', 'bold');
        foreach ($this->action->instances as $index => $id) {
            $names = $names->add(($index > 0 ? ', ' : '').$id);
            if ($app->isProd($id)) {
                $names = $names->add(' ')->add('PROD', 'prod');
            }
        }
        $lines = [$names];
        if (count($this->action->instances) > 1) {
            $lines[] = Line::of('   '.$t->t('preview.order', $this->action->instances[0], count($this->action->instances) - 1));
            $lines[] = Line::of('   '.$t->t('preview.stop'));
        }

        return $lines;
    }

    /**
     * @return list<Line>
     */
    private function simulationLines(App $app): array
    {
        $task = $this->result;
        if (null === $task) {
            return [];
        }
        $lines = [Line::of('   '.$app->t->t('preview.dolicli_will'), 'bold')];
        $actions = [];
        $errors = [];
        foreach ($this->action->instances as $id) {
            $outcome = $task->outcome($id);
            if ([] !== $outcome && empty($outcome['ok'])) {
                $errors[] = $id.': '.(string) ($outcome['error'] ?? '');
                continue;
            }
            foreach ((array) ($task->data($id)['actions'] ?? []) as $text) {
                $actions[(string) $text] = true;
            }
        }
        foreach (array_keys($actions) as $text) {
            $lines[] = Line::of('     - '.$text);
        }
        if ([] === $actions && [] === $errors) {
            $lines[] = Line::of('     '.('' !== $task->error() ? $task->error() : $app->t->t('preview.no_detail')), Task::DONE === $task->state() ? 'dim' : 'error');
        }
        foreach ($errors as $error) {
            $lines[] = Line::of('     !! '.$error, 'crit');
        }

        return $lines;
    }

    /**
     * @return list<Line>
     */
    private function planLines(App $app): array
    {
        $task = $this->result;
        if (null === $task) {
            return [];
        }
        $t = $app->t;
        $plan = $task->json;
        if (null === $plan || ! isset($plan['steps'])) {
            return [Line::of('   !! '.('' !== $task->error() ? $task->error() : $t->t('preview.no_plan')), 'crit')];
        }
        $lines = [Line::of('   '.$t->t('preview.plan', (string) ($plan['from'] ?? '?'), (string) ($plan['to'] ?? '?'), (string) ($plan['php_cli'] ?? '?')), 'bold')];
        foreach ((array) ($plan['blockers'] ?? []) as $blocker) {
            $lines[] = Line::of('     !! '.$t->t('preview.blocker').' '.(string) $blocker, 'crit');
        }
        foreach ((array) ($plan['warnings'] ?? []) as $warning) {
            $lines[] = Line::of('     !  '.(string) $warning, 'warn');
        }
        foreach ((array) $plan['steps'] as $index => $step) {
            $id = (string) ($step['id'] ?? '');
            $label = $t->has('upgrade.step.'.$id) ? $t->t('upgrade.step.'.$id) : (string) ($step['description'] ?? $id);
            $lines[] = Line::of('     '.($index + 1).'. '.$label.('manual' === ($step['type'] ?? '') ? '  ('.$t->t('preview.manual').')' : ''));
        }

        return $lines;
    }

    private function execute(App $app): void
    {
        $operation = $app->operations->create(
            $this->action->kind,
            $this->action->title.' - '.(1 === count($this->action->instances) ? $this->action->instances[0] : $app->t->t('preview.instances', count($this->action->instances))),
            $this->action->instances,
        );
        $app->operations->run($operation, $this->action->args, ['--confirm='.$this->action->cliAnswer($this->protected($app))], $this->action->purpose);
        $app->pop();
        $app->toast($app->t->t('op.started', $operation->title));
    }
}
