<?php

declare(strict_types=1);

namespace Dolifleet;

use Dolifleet\Command\AddCommand;
use Dolifleet\Command\BackupCommand;
use Dolifleet\Command\CheckCommand;
use Dolifleet\Command\ConfigCommand;
use Dolifleet\Command\ConsoleCommand;
use Dolifleet\Command\DiscoverCommand;
use Dolifleet\Command\ExecCommand;
use Dolifleet\Command\HostKeyCommand;
use Dolifleet\Command\JobsCommand;
use Dolifleet\Command\LogCommand;
use Dolifleet\Command\MaintenanceCommand;
use Dolifleet\Command\ServeCommand;
use Dolifleet\Command\SessionsCommand;
use Dolifleet\Command\SetupCommand;
use Dolifleet\Command\StatusCommand;
use Dolifleet\Command\UpgradeCommand;
use Dolifleet\Console\Settings;
use Dolifleet\Inventory\Inventory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use RuntimeException;

/**
 * dolifleet: the pilot of a fleet of Dolibarr servers, on the workstation of the administrator.
 */
final class Kernel
{
    public static function application(): Application
    {
        $application = new Application('dolifleet', self::version());
        $application->addCommands([
            new ConsoleCommand(),
            new ConfigCommand(),
            new DiscoverCommand(),
            new CheckCommand(),
            new StatusCommand(),
            new ExecCommand(),
            new LogCommand(),
            new AddCommand(),
            new HostKeyCommand(),
            new SetupCommand(),
            new MaintenanceCommand(),
            new BackupCommand(),
            new SessionsCommand(),
            new JobsCommand(),
            new UpgradeCommand(),
            new ServeCommand(),
        ]);
        $application->setDefaultCommand('console');

        return $application;
    }

    /**
     * Arguments of the command line, with two shortcuts: dolifleet <instance> opens the console
     * on the card of an instance of the inventory, and dolifleet alone prints the state of the
     * fleet instead of opening the console when the settings say console: false.
     *
     * @param list<string> $argv
     */
    public static function input(Application $application, array $argv): ArgvInput
    {
        // First word that is not an option (global options such as --no-interaction come first).
        $position = null;
        foreach (array_slice($argv, 1, null, true) as $index => $word) {
            if ( ! str_starts_with($word, '-')) {
                $position = $index;
                break;
            }
        }
        $first = null === $position ? null : $argv[$position];
        if (null === $first) {
            try {
                if ( ! Settings::load()->console()) {
                    $argv[] = '--plain';
                }
            } catch (RuntimeException) {
                // An unreadable settings file must not prevent the console from opening.
            }

            return new ArgvInput($argv);
        }
        if ( ! $application->has($first) && 1 === preg_match(Inventory::ID_PATTERN, $first)) {
            try {
                $file = Paths::inventory();
                if (is_file($file) && isset(Inventory::load($file)->instances()[$first])) {
                    array_splice($argv, (int) $position, 0, ['console']);
                }
            } catch (RuntimeException) {
                // Invalid inventory: the command runs as typed and reports it.
            }
        }

        return new ArgvInput($argv);
    }

    public static function version(): string
    {
        try {
            return Remote\Body::locate()->version();
        } catch (RuntimeException) {
            return 'dev';
        }
    }
}
