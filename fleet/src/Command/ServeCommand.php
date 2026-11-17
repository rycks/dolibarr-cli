<?php

declare(strict_types=1);

namespace Dolifleet\Command;

use Dolifleet\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * Local dashboard: built-in PHP server on 127.0.0.1, on a free port chosen at start (tried
 * again when taken meanwhile), stopped with the command. The token is printed here and given
 * to the server through its environment: never on a command line (xdg-open would expose it).
 */
#[AsCommand(name: 'serve', description: 'Local web dashboard of the fleet (127.0.0.1 only)')]
final class ServeCommand extends FleetCommand
{
    protected function configure(): void
    {
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port (default: a free one)', '0');
    }

    protected function handle(): int
    {
        $this->inventory();
        $token = bin2hex(random_bytes(24));
        $router = dirname(__DIR__, 2).'/web/router.php';
        if (str_starts_with($router, 'phar://')) {
            // php -S cannot read its router in a phar: a relay in a private directory requires it.
            $dir = sys_get_temp_dir().'/dolifleet-serve-'.bin2hex(random_bytes(6));
            Paths::ensure($dir);
            $relay = $dir.'/router.php';
            if (false === file_put_contents($relay, '<?php require '.var_export($router, true).";\n")) {
                throw new RuntimeException("Cannot write {$relay}");
            }
            $router = $relay;
        }
        $fixed = (int) $this->input->getOption('port');

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $port = $fixed > 0 ? $fixed : self::freePort();
            $env = [
                'DOLIFLEET_SERVE_TOKEN' => $token,
                'DOLIFLEET_SERVE_PORT' => (string) $port,
                'DOLIFLEET_INVENTORY' => Paths::inventory(),
            ];
            $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], dirname($router), $env, null, null);
            $server->start();
            usleep(400000);
            if ( ! $server->isRunning()) {
                if ($fixed > 0) {
                    $this->error('Cannot listen on 127.0.0.1:'.$port.': '.trim($server->getErrorOutput()));

                    return self::EXIT_FAILED;
                }
                continue;
            }
            $this->output->writeln('Dashboard: http://127.0.0.1:'.$port.'/?token='.$token);
            $this->output->writeln('Open this URL in your browser (the token is exchanged for a cookie). Ctrl-C stops the dashboard.');
            $server->wait(function (string $type, string $data): void {
                if (Process::ERR === $type) {
                    $this->output->write($data, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
                }
            });

            return 0 === $server->getExitCode() ? self::EXIT_OK : self::EXIT_FAILED;
        }
        $this->error('No free port found for the dashboard.');

        return self::EXIT_FAILED;
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $socket) {
            throw new RuntimeException("Cannot find a free port: {$error}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) mb_substr($name, (int) mb_strrpos($name, ':') + 1);
    }
}
