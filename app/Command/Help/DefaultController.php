<?php

declare(strict_types=1);

namespace App\Command\Help;

use App\Support\Command;

/**
 * General help, built from the commands found in app/Command (and their summary()).
 * Shown by `./dolibarr help` and by `./dolibarr` without argument.
 */
class DefaultController extends Command
{
    public function handle(): void
    {
        $this->rawOutput($this->getApp()->getSignature()."\n\n");

        if (defined('DOL_VERSION')) {
            $this->info('Dolibarr '.DOL_VERSION.' in '.DOL_DOCUMENT_ROOT."\n");
        }

        $registry = $this->getApp()->commandRegistry;
        $map = $registry->getCommandMap();
        ksort($map);

        $this->info("Commands:\n");
        foreach (array_keys($map) as $command) {
            if ('help' === $command) {
                continue;
            }
            $controllers = $registry->getNamespace((string) $command)?->getControllers() ?? [];
            ksort($controllers);

            $lines = [];
            foreach ($controllers as $name => $controller) {
                $summary = $controller instanceof Command ? $controller->summary() : '';
                if ('' === $summary) {
                    continue;
                }
                $usage = './dolibarr '.$command.('default' === $name ? '' : ' '.$name);
                $lines[] = '   '.str_pad($usage, 40).'- '.$summary;
            }
            if ([] === $lines) {
                continue;
            }
            $this->rawOutput('  '.mb_strtoupper((string) $command)."\n".implode("\n", $lines)."\n\n");
        }

        $this->info('Usage:');
        $this->rawOutput('  ./dolibarr <category> <command> [key=value...] [--flag...]
  ./dolibarr <category> <command> --help     detailed help of a command
  ./dolibarr <category>                      commands of a category

Global options and environment:
  DOLPATH=/path/to/htdocs   Dolibarr to work on (default: ./htdocs)
  entity=N                  Company (entity) to work on in a multicompany setup
  DOLICLI_DEBUG=1           Print the stack trace of unexpected errors

');
    }
}
