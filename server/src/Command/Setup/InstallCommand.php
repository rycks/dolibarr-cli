<?php

namespace Dolicli\Command\Setup;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Setup\Installer;
use Dolicli\System\Paths;

final class InstallCommand extends Command
{
    public function name(): string
    {
        return 'setup install';
    }

    public function summary(): string
    {
        return 'Install dolicli on the server: code owned by root, state directories, cron of the collections';
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
        return [
            Param::value('body', 'PATH', 'Built body to install (default: the one running)'),
            Param::value('launcher', 'PATH', 'Launcher to install (default: the one next to the body)'),
            Param::flag('force', 'Replace a newer installed version'),
            Param::flag('config-only', 'Only the directories and the cron file, not the code'),
        ];
    }

    public function help(): string
    {
        return "Run as root. Installs /usr/local/bin/dolicli and /usr/local/lib/dolicli/dolicli.php (root, not\n"
            . "writable by the web account), creates /etc/dolicli/ (instances.ini template when absent),\n"
            . "/var/lib/dolicli/<run_as>/ (owner run_as, group monitor_group, mode 2750), the prom_dir declared,\n"
            . 'and writes /etc/cron.d/dolicli from instances.ini. Run it again after any change of instances.ini.';
    }

    public function execute(Context $ctx): array
    {
        $installer = new Installer();
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => [
                'Install ' . Paths::installedLauncher() . ' and ' . Paths::installedBody(),
                'Prepare ' . Paths::etcDir() . ' and ' . Paths::stateDir(),
                'Write ' . Paths::cronFile() . ' from ' . Paths::instancesIni(),
            ]];
        }
        if (!$ctx->flag('config-only')) {
            list($launcher, $body) = Installer::runningFiles();
            $body = (string) ($ctx->value('body') ?: $body);
            $launcher = (string) ($ctx->value('launcher') ?: $launcher);
            if ('' === $body || '' === $launcher || !is_file($body) || !is_file($launcher)) {
                throw Failure::usage('BODY_NOT_BUILT', 'No built body to install: run make build and use dist/dolicli, or give body= and launcher=.');
            }
            $installer->installCode($launcher, $body, $ctx->flag('force'));
        }
        foreach ($installer->configure() as $warning) {
            $ctx->warn('SETUP_WARNING', $warning);
        }

        return ['actions' => $installer->actions()];
    }

    public function renderText(array $data): string
    {
        return implode("\n", $data['actions']) . "\n";
    }
}
