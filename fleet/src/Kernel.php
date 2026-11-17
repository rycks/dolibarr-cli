<?php

declare(strict_types=1);

namespace Dolifleet;

use Dolifleet\Command\CheckCommand;
use Dolifleet\Command\ConfigCommand;
use Dolifleet\Command\DiscoverCommand;
use Dolifleet\Command\ExecCommand;
use Dolifleet\Command\LogCommand;
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
