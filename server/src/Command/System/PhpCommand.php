<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Cli\Text;
use Dolicli\Instance\Resolver;
use Dolicli\Php\Binaries;
use Dolicli\Php\Matrix;
use Dolicli\Upgrade\MigrationPlanner;

final class PhpCommand extends Command
{
    public function name(): string
    {
        return 'system php';
    }

    public function summary(): string
    {
        return 'List the PHP CLI binaries of the server, their extensions and compatibility with Dolibarr';
    }

    public function needsDolibarr(): bool
    {
        return false;
    }

    public function allowedAsRoot(): bool
    {
        return true;
    }

    public function params(): array
    {
        return [Param::value('dolibarr', 'X.Y.Z', 'Dolibarr version to check against (default: the version of the instance, when found)')->matching('/^\d+\.\d+\.\d+/')];
    }

    public function help(): string
    {
        return "Looks in /usr/bin, /usr/local/bin, Remi, Plesk, cPanel and CloudLinux locations. Compatibility\n"
            . "follows the PHP range declared by Dolibarr (install/check.php) and, for versions that declare no\n"
            . 'maximum, the real maximums known to dolicli: ok, warning (below the advised minimum), incompatible, unknown.';
    }

    public function execute(Context $ctx): array
    {
        $dolibarr = (string) $ctx->value('dolibarr');
        if ('' === $dolibarr) {
            $instance = Resolver::tryResolve($ctx->value('instance'), $ctx->value('dolpath'));
            if (null !== $instance) {
                $ctx->setInstance($instance);
                $dolibarr = $instance->filesVersion();
            }
        }
        $dolibarr = '' === $dolibarr ? '' : MigrationPlanner::clean($dolibarr);

        $binaries = [];
        foreach ((new Binaries())->all() as $binary) {
            $binary['compatibility'] = '' === $dolibarr ? null : Matrix::check($dolibarr, $binary['version']);
            $binaries[] = $binary;
        }

        return [
            'dolibarr_version' => '' === $dolibarr ? null : $dolibarr,
            'range' => '' === $dolibarr ? null : Matrix::range($dolibarr),
            'binaries' => $binaries,
        ];
    }

    public function renderText(array $data): string
    {
        $out = '';
        if (null !== $data['dolibarr_version']) {
            $range = $data['range'];
            $out .= "Dolibarr {$data['dolibarr_version']}: PHP {$range['min']} minimum, {$range['recommended']} advised, "
                . ('' !== $range['max'] ? $range['max'] . ' maximum' : 'no established maximum') . "\n\n";
        }
        $rows = [];
        foreach ($data['binaries'] as $binary) {
            $rows[] = [$binary['path'], $binary['version'], null === $binary['compatibility'] ? '' : $binary['compatibility'], implode(' ', $binary['missing'])];
        }
        if ([] !== $rows) {
            $out .= Text::table(['Binary', 'Version', 'Compatibility', 'Missing extensions'], $rows);
        }

        return '' === $out ? "No PHP binary found.\n" : $out;
    }
}
