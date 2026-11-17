<?php

declare(strict_types=1);

namespace Dolifleet;

use Dolifleet\Inventory\Instance;
use Dolifleet\Inventory\Inventory;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Remote\Dolicli;
use Dolifleet\Remote\Outcome;
use Dolifleet\Store\Journal;

/**
 * Run one dolicli command on a target with the guards of the manifest (6.6):
 *
 * - read commands run in parallel (at most `parallel` at a time);
 * - writes run in series, starting with one witness instance, and stop at the first error
 *   unless --continue-on-error;
 * - destructive commands (anonymize run, upgrade steps) are refused on several instances;
 * - protected instances (protected_tags) need the instance name typed again, a batch the exact
 *   target; --yes is for scripts and is journaled;
 * - every write is journaled before (intention) and after (result).
 */
final class Batch
{
    /** @var callable(string): ?string */
    private $ask;

    /** @var callable(string): void */
    private $say;

    /**
     * @param callable(string): ?string $ask Question to the operator, null without terminal
     * @param callable(string): void $say Progress message
     */
    public function __construct(
        private readonly Inventory $inventory,
        private readonly Dolicli $dolicli,
        private readonly Journal $journal,
        callable $ask,
        callable $say,
    ) {
        $this->ask = $ask;
        $this->say = $say;
    }

    /**
     * @param array<string, Instance> $instances
     * @param list<string> $arguments dolicli arguments, command words first
     * @param array{yes?: bool, answer?: string, dry_run?: bool, continue_on_error?: bool, target?: string, extra?: callable(Instance): list<string>, detach?: bool} $options
     * @return array<string, Outcome>
     */
    public function run(array $instances, array $arguments, array $options = []): array
    {
        $found = $this->dolicli->body()->commandOf($arguments);
        if (null === $found) {
            throw new InventoryException('Unknown dolicli command: '.implode(' ', array_slice($arguments, 0, 3)).' (dolicli help lists them).');
        }
        [$entry] = $found;
        $category = (string) $entry['category'];
        $name = (string) $entry['name'];
        $dryRun = ! empty($options['dry_run']);
        if ($dryRun) {
            $arguments[] = '--dry-run';
        }

        if ('read' === $category || $dryRun) {
            if (isset($options['extra'])) {
                $outcomes = [];
                foreach ($instances as $id => $instance) {
                    $outcomes[$id] = $this->dolicli->run($instance, array_merge($arguments, ($options['extra'])($instance)));
                }

                return $outcomes;
            }

            return $this->dolicli->runMany($instances, $arguments, (int) $this->inventory->default('parallel'));
        }
        if (count($instances) > 1 && (empty($entry['batch']) || 'destructive' === $category)) {
            throw new InventoryException("'{$name}' is {$category} and not allowed on several instances: target one instance.");
        }
        $this->confirm($instances, $name, $options);

        $run = date('YmdHis').'-'.bin2hex(random_bytes(3));
        $sensitive = array_values(array_map('strval', (array) ($entry['sensitive_params'] ?? [])));
        $this->journal->intention($run, (string) ($options['target'] ?? implode(',', array_keys($instances))), $name, $arguments, $sensitive, [
            'instances' => array_keys($instances),
            'yes' => ! empty($options['yes']),
            'via' => self::frontEnd(),
        ]);

        $outcomes = [];
        $first = true;
        foreach ($instances as $id => $instance) {
            ($this->say)("{$id}: {$name}".($first && count($instances) > 1 ? ' (witness instance)' : ''));
            $instanceArguments = array_merge($arguments, isset($options['extra']) ? ($options['extra'])($instance) : [], ['--yes']);
            if ( ! empty($options['detach'])) {
                // Long operation: started detached on the server, followed with dolifleet jobs.
                $jobId = $run.'-'.$id;
                $outcome = $this->dolicli->run($instance, ['job', 'start', 'id='.$jobId, 'args='.json_encode($this->dolicli->jobArguments($instance, $instanceArguments))], 600, [
                    'body' => $this->dolicli->body()->content(),
                ]);
            } else {
                $outcome = $this->dolicli->run($instance, $instanceArguments);
            }
            $outcomes[$id] = $outcome;
            $this->journal->result($run, $id, $name, $outcome->exitCode, $outcome->ok() ? 'ok' : $outcome->error);
            if ( ! $outcome->ok() && empty($options['continue_on_error'])) {
                ($this->say)("{$id} failed: stopping (--continue-on-error to go on)");
                break;
            }
            $first = false;
        }

        return $outcomes;
    }

    /**
     * Front end that started the pilot (DOLIFLEET_FRONTEND: console, dashboard), cli otherwise.
     */
    public static function frontEnd(): string
    {
        $front = getenv('DOLIFLEET_FRONTEND');

        return is_string($front) && 1 === preg_match('/^[a-z]{1,20}$/', $front) ? $front : 'cli';
    }

    /**
     * The answer comes from the operator, or from a front end that asked the same question
     * itself (--confirm): it is checked against the same expected value either way.
     *
     * @param array<string, Instance> $instances
     * @param array{yes?: bool, answer?: string, target?: string} $options
     */
    private function confirm(array $instances, string $command, array $options): void
    {
        if ( ! empty($options['yes'])) {
            return;
        }
        $protected = array_filter($instances, fn (Instance $i): bool => $i->isProtected($this->inventory->protectedTags()));
        if (1 === count($instances)) {
            $instance = reset($instances);
            $expected = [] !== $protected ? $instance->id : 'yes';
            $question = [] !== $protected
                ? "{$command} on the protected instance {$instance->id}. Type its name to confirm: "
                : "{$command} on {$instance->id}? Type yes to confirm: ";
        } else {
            $target = (string) ($options['target'] ?? '');
            ($this->say)("{$command} on ".count($instances).' instances: '.implode(', ', array_keys($instances))
                .([] !== $protected ? ' (protected: '.implode(', ', array_keys($protected)).')' : ''));
            $expected = '' !== $target ? $target : 'yes';
            $question = "Type the exact target ({$expected}) to confirm: ";
        }
        $answer = isset($options['answer']) && '' !== $options['answer'] ? $options['answer'] : ($this->ask)($question);
        if (null === $answer) {
            throw new InventoryException('Confirmation required: run in a terminal, or add --yes (journaled).');
        }
        if (trim($answer) !== $expected) {
            throw new InventoryException('Not confirmed: nothing was done.');
        }
    }

    /**
     * Exit code of the pilot: 0 all succeeded, 1 at least one failed, 3 no instance reached.
     *
     * @param array<string, Outcome> $outcomes
     */
    public static function exitCode(array $outcomes): int
    {
        if ([] === $outcomes) {
            return 1;
        }
        $reached = array_filter($outcomes, static fn (Outcome $o): bool => $o->reached);
        if ([] === $reached) {
            return 3;
        }
        foreach ($outcomes as $outcome) {
            if ( ! $outcome->ok()) {
                return 1;
            }
        }

        return 0;
    }
}
