<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Console\App;
use Dolifleet\Console\Console;
use Dolifleet\Console\Data\Row;
use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Screen\FleetScreen;
use Dolifleet\Console\Screen\InstanceScreen;
use Dolifleet\Console\Screen\WelcomeScreen;
use Dolifleet\Console\Settings;
use Dolifleet\Console\Tasks\CliBackend;
use Dolifleet\Console\Terminal\Renderer;
use Dolifleet\Console\Terminal\Style;
use Dolifleet\Console\Terminal\Terminal;
use Dolifleet\Console\View\Signs;
use Dolifleet\Console\View\Table;
use Dolifleet\Inventory\InventoryException;
use Dolifleet\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console: what dolifleet alone opens on a terminal. Outside a terminal (script, pipe) or
 * with --plain, the state of the fleet is printed from the local data, as text or JSON.
 */
#[AsCommand(name: 'console', description: 'Full-screen console of the fleet (dolifleet alone opens it on a terminal)')]
final class ConsoleCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addArgument('instance', InputArgument::OPTIONAL, 'Open the card of this instance')
            ->addOption('lang', null, InputOption::VALUE_REQUIRED, 'Language of the console (fr, en...); list shows the languages available')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Print the state of the fleet instead of opening the console')
            ->addOption('no-color', null, InputOption::VALUE_NONE, 'No color (NO_COLOR is also honoured)')
            ->addJsonOption();
    }

    protected function handle(): int
    {
        $settings = Settings::load();
        $option = $this->input->getOption('lang');
        if ('list' === $option) {
            foreach (Translator::available() as $code => $coverage) {
                $this->output->writeln(sprintf('%s  %3d %%', $code, $coverage));
            }

            return self::EXIT_OK;
        }
        $t = Translator::resolve(is_string($option) ? $option : null, $settings);
        if (is_string($option) && $t->code() !== Translator::normalize($option)) {
            $this->error("Unknown language '{$option}': ".implode(', ', array_keys(Translator::available())).'. Using '.$t->code().'.');
        }
        $app = new App($t, Style::fromEnvironment((bool) $this->input->getOption('no-color')), new CliBackend(CliBackend::self()), $this->store(), Paths::inventory(), $settings);
        $hasInventory = $app->hasInventory();
        $instance = (string) $this->input->getArgument('instance');
        if ('' !== $instance && ( ! $hasInventory || ! isset($app->inventory()->instances()[$instance]))) {
            throw new InventoryException("Unknown instance '{$instance}' (dolifleet status all lists them).");
        }

        if ($this->isJson() || $this->input->getOption('plain') || ! Terminal::interactive()) {
            return $this->plain($app);
        }

        $app->push($hasInventory ? new FleetScreen() : new WelcomeScreen());
        if ('' !== $instance) {
            $app->push(new InstanceScreen($instance));
        }
        if ($hasInventory) {
            $app->refresh(array_keys($app->inventory()->instances()));
        }
        (new Console($app, new Terminal(), new Renderer($app->style)))->run();
        $this->recap($app);

        return self::EXIT_OK;
    }

    /**
     * State of the fleet from the local data, nothing contacted: dolifleet status refreshes it.
     */
    private function plain(App $app): int
    {
        $t = $app->t;
        if ( ! $app->hasInventory()) {
            if ($this->isJson()) {
                $this->json(['instances' => []]);
            } else {
                $this->output->writeln($t->t('welcome.line1'));
                $this->output->writeln($t->t('welcome.cli').' dolifleet add <alias-ssh>');
            }

            return self::EXIT_OK;
        }
        $rows = $app->rows();
        usort($rows, static fn (Row $a, Row $b): int => [$b->gravity(), $a->id()] <=> [$a->gravity(), $b->id()]);
        if ($this->isJson()) {
            $out = [];
            foreach ($rows as $row) {
                $out[$row->id()] = [
                    'server' => $row->instance->server,
                    'prod' => $row->prod,
                    'status' => $row->status,
                    'age' => $row->age,
                    'version' => $row->version(),
                    'php' => $row->php(),
                    'backup_age' => $row->backupAge(),
                    'maintenance' => $row->maintenance(),
                    'problems' => array_map(static fn ($p): array => ['check' => $p->check, 'status' => $p->status, 'text' => $p->text, 'message' => $p->message], $row->problems),
                ];
            }
            $this->json(['instances' => $out]);

            return self::EXIT_OK;
        }
        $table = (new Table())
            ->column('instance', $t->t('col.instance'), 12, 30, 0)
            ->column('version', $t->t('col.version'), 7, 10, 3)
            ->column('state', $t->t('col.state'), 9, 14, 0)
            ->column('backup', $t->t('col.backup'), 6, 9, 4)
            ->column('maintenance', $t->t('col.maintenance'), 5, 6, 5)
            ->column('age', $t->t('col.age'), 6, 16, 2)
            ->column('problem', $t->t('col.problem'), 28, 200, 1, 'left', true);
        foreach ($rows as $row) {
            $backup = $row->backupAge();
            $maintenance = $row->maintenance();
            $table->row([
                'instance' => $row->id().($row->prod ? ' PROD' : ''),
                'version' => $row->version(),
                'state' => Signs::rowState($t, $row),
                'backup' => null === $backup ? '' : $t->duration($backup),
                'maintenance' => null === $maintenance ? '' : ($maintenance ? 'ON' : 'off'),
                'age' => null === $row->age ? $t->t('fleet.never') : $t->ago($row->age),
                'problem' => [] === $row->problems ? '' : $row->problems[0]->text,
            ]);
        }
        $width = max(80, (new \Symfony\Component\Console\Terminal())->getWidth());
        foreach (Renderer::plain($table->render($width, count($rows) + 1), $width) as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }
        $this->output->writeln($t->t('plain.footer'), OutputInterface::OUTPUT_RAW);

        return self::EXIT_OK;
    }

    /**
     * What the session changed, as commands to replay or to paste in a ticket.
     */
    private function recap(App $app): void
    {
        $recap = $app->recap();
        if ([] === $recap) {
            return;
        }
        $t = $app->t;
        $this->output->writeln($t->t('recap.title'), OutputInterface::OUTPUT_RAW);
        foreach ($recap as $entry) {
            $this->output->writeln('  '.$t->time((int) $entry['time']).'  '.$entry['command'].('' !== $entry['result'] ? '   '.$entry['result'] : '   '.$t->t('recap.running')), OutputInterface::OUTPUT_RAW);
        }
        $this->output->writeln($t->t('recap.footer'), OutputInterface::OUTPUT_RAW);
    }
}
