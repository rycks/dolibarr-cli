<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Batch;
use Dolifleet\Inventory\Instance;
use Dolifleet\Inventory\Inventory;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Inventory\Targets;
use Dolifleet\Paths;
use Dolifleet\Remote\Body;
use Dolifleet\Remote\Dolicli;
use Dolifleet\Remote\Outcome;
use Dolifleet\Remote\SshTransport;
use Dolifleet\Remote\Transport;
use Dolifleet\Store\Journal;
use Dolifleet\Store\StateStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;
use RuntimeException;

/**
 * Shared plumbing of the pilot commands: inventory, transport, body, stores, output.
 * Exit codes: 0 everything succeeded, 1 at least one instance failed, 2 invalid usage
 * (inventory, empty target), 3 no instance reached.
 */
abstract class FleetCommand extends Command
{
    public const EXIT_OK = 0;
    public const EXIT_FAILED = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_UNREACHABLE = 3;

    private ?Inventory $inventory = null;

    private ?Body $body = null;

    private ?Transport $transport = null;

    private ?StateStore $store = null;

    protected InputInterface $input;

    protected OutputInterface $output;

    /**
     * @return int Exit code
     */
    abstract protected function handle(): int;

    protected function addJsonOption(): static
    {
        return $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        try {
            return $this->handle();
        } catch (InventoryException $e) {
            $this->error($e->getMessage());

            return self::EXIT_USAGE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::EXIT_FAILED;
        }
    }

    protected function inventory(): Inventory
    {
        return $this->inventory ??= Inventory::load(Paths::inventory());
    }

    protected function body(): Body
    {
        return $this->body ??= Body::locate();
    }

    protected function transport(): Transport
    {
        $timeout = is_file(Paths::inventory()) ? (int) ($this->inventory()->default('ssh_timeout') ?? 10) : 10;

        return $this->transport ??= new SshTransport($timeout);
    }

    /**
     * Test hook: replace the SSH transport.
     */
    public function useTransport(Transport $transport): void
    {
        $this->transport = $transport;
    }

    protected function store(): StateStore
    {
        return $this->store ??= new StateStore();
    }

    protected function dolicli(): Dolicli
    {
        $inventory = $this->inventory();

        return new Dolicli($inventory, $this->transport(), $this->body(), $this->store());
    }

    protected function batch(): Batch
    {
        return new Batch(
            $this->inventory(),
            $this->dolicli(),
            new Journal(),
            fn (string $question): ?string => $this->ask($question),
            fn (string $message) => $this->progress($message)
        );
    }

    /**
     * @return array<string, Instance>
     */
    protected function targets(string $target): array
    {
        $exclude = $this->getDefinition()->hasOption('exclude') ? (string) $this->input->getOption('exclude') : '';

        return Targets::resolve($this->inventory(), $target, $exclude);
    }

    protected function addTargetOptions(): static
    {
        return $this->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Target to remove from the selection', '');
    }

    /**
     * Answer to the confirmation question given by a front end that asked it itself (console,
     * dashboard). Checked against the expected answer exactly like a typed one.
     */
    protected function addConfirmOption(): static
    {
        return $this->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Answer to the confirmation question (front ends)', '');
    }

    protected function confirmAnswer(): string
    {
        return $this->getDefinition()->hasOption('confirm') ? (string) $this->input->getOption('confirm') : '';
    }

    protected function ask(string $question): ?string
    {
        if ( ! $this->input->isInteractive() || ! (function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN))) {
            return null;
        }
        $helper = $this->getHelper('question');
        if ( ! $helper instanceof QuestionHelper) {
            return null;
        }
        $answer = $helper->ask($this->input, $this->output, new Question($question));

        return is_string($answer) ? $answer : '';
    }

    protected function progress(string $message): void
    {
        if ( ! $this->isJson()) {
            $this->output->writeln('<comment>'.self::clean($message).'</comment>', OutputInterface::VERBOSITY_NORMAL);
        }
    }

    protected function error(string $message): void
    {
        $stderr = method_exists($this->output, 'getErrorOutput') ? $this->output->getErrorOutput() : $this->output;
        $stderr->writeln('<error>'.self::clean($message).'</error>');
    }

    protected function isJson(): bool
    {
        return $this->getDefinition()->hasOption('json') && (bool) $this->input->getOption('json');
    }

    /**
     * @param mixed $data
     */
    protected function json($data): void
    {
        $this->output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
    }

    /**
     * Table of outcomes, values from the servers cleaned of control characters and escaped.
     *
     * @param array<string, Outcome> $outcomes
     * @param callable(Outcome): string $describe
     */
    protected function outcomes(array $outcomes, callable $describe): int
    {
        $exit = Batch::exitCode($outcomes);
        if ($this->isJson()) {
            $rows = [];
            foreach ($outcomes as $id => $outcome) {
                $rows[$id] = ['ok' => $outcome->ok(), 'exit_code' => $outcome->exitCode, 'error' => $outcome->error, 'envelope' => $outcome->envelope];
            }
            $this->json(['exit_code' => $exit, 'instances' => $rows]);

            return $exit;
        }
        foreach ($outcomes as $id => $outcome) {
            $status = $outcome->ok() ? '<info>ok</info>' : '<error>failed</error>';
            $this->output->writeln(sprintf('%-28s %s  %s', $id, $status, self::clean($outcome->ok() ? $describe($outcome) : $outcome->error)));
        }

        return $exit;
    }

    /**
     * Data from a server: no control character, no console markup.
     */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $text);

        return str_replace('<', '\\<', $text);
    }
}
