<?php

declare(strict_types=1);

namespace Dolifleet;

use Dolifleet\Command\AddCommand;
use Dolifleet\Command\BackupCommand;
use Dolifleet\Command\CheckCommand;
use Dolifleet\Command\ConfigCommand;
use Dolifleet\Command\DiscoverCommand;
use Dolifleet\Command\ExecCommand;
use Dolifleet\Command\JobsCommand;
use Dolifleet\Command\LogCommand;
use Dolifleet\Command\MaintenanceCommand;
use Dolifleet\Command\SessionsCommand;
use Dolifleet\Command\SetupCommand;
use Dolifleet\Command\StatusCommand;
use Symfony\Component\Console\Application;
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
            new ConfigCommand(),
            new DiscoverCommand(),
            new CheckCommand(),
            new StatusCommand(),
            new ExecCommand(),
            new LogCommand(),
            new AddCommand(),
            new SetupCommand(),
            new MaintenanceCommand(),
            new BackupCommand(),
            new SessionsCommand(),
            new JobsCommand(),
        ]);

        return $application;
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
