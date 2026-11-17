<?php

namespace Dolicli\Command\Setup;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Setup\Installer;
use Dolicli\System\Paths;

final class UninstallCommand extends Command
{
    public function name(): string
    {
        return 'setup uninstall';
    }

    public function summary(): string
    {
        return 'Remove what setup install created (cron, code; configuration and state files with --purge)';
    }

    public function category(): string
    {
        return self::WRITE;
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
        return [Param::flag('purge', 'Also remove /etc/dolicli and /var/lib/dolicli')];
    }

    public function execute(Context $ctx): array
    {
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => array_merge(
                ['Remove ' . Paths::cronFile(), 'Remove ' . Paths::installedLauncher(), 'Remove ' . Paths::installedBody()],
                $ctx->flag('purge') ? ['Remove ' . Paths::etcDir(), 'Remove ' . Paths::stateDir()] : []
            )];
        }
        if (!$ctx->confirm('Remove dolicli from this server?')) {
            return ['actions' => []];
        }
        $installer = new Installer();
        $installer->uninstall($ctx->flag('purge'));

        return ['actions' => $installer->actions()];
    }

    public function renderText(array $data): string
    {
        return [] === $data['actions'] ? "Nothing removed.\n" : implode("\n", $data['actions']) . "\n";
    }
}
