<?php

namespace Dolicli\Command\Setup;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Instance\InstancesIni;
use Dolicli\Setup\Installer;
use Dolicli\System\Files;
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
            Param::flag('from-stream', 'Body, launcher and instances.ini sent in the stream by dolifleet setup'),
            Param::value('sha256', 'HEX', 'With --from-stream, expected SHA256 of the body')->matching('/^[0-9a-f]{64}$/'),
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
        if ($ctx->flag('from-stream')) {
            $this->installFromStream($ctx, $installer);
        } elseif (!$ctx->flag('config-only')) {
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

    /**
     * Files of dolifleet setup: checked (SHA256 of the body, instances.ini parsed) before
     * anything is replaced.
     */
    private function installFromStream(Context $ctx, Installer $installer)
    {
        $files = isset($GLOBALS['DOLICLI_FILES']) && is_array($GLOBALS['DOLICLI_FILES']) ? $GLOBALS['DOLICLI_FILES'] : [];
        $decoded = [];
        foreach (['body', 'launcher', 'instances.ini'] as $name) {
            if (isset($files[$name])) {
                $decoded[$name] = (string) base64_decode((string) $files[$name], true);
            }
        }
        if (!isset($decoded['body'], $decoded['launcher']) || '' === $decoded['body']) {
            throw Failure::usage('MISSING_FILES', '--from-stream needs the body and the launcher in the stream.');
        }
        $expected = (string) $ctx->value('sha256');
        if ('' === $expected || hash('sha256', $decoded['body']) !== $expected) {
            throw Failure::operation('BODY_CORRUPTED', 'SHA256 of the received body does not match: transfer corrupted, nothing installed.');
        }
        $work = Files::tempDir();
        try {
            Files::writeAtomic($work . '/dolicli.php', $decoded['body'], 0644);
            Files::writeAtomic($work . '/dolicli', $decoded['launcher'], 0755);
            if (isset($decoded['instances.ini'])) {
                Files::writeAtomic($work . '/instances.ini', $decoded['instances.ini'], 0644);
                $errors = InstancesIni::load($work . '/instances.ini')->errors();
                if ([] !== $errors) {
                    throw Failure::usage('INSTANCES_INI_INVALID', 'Received instances.ini is invalid: ' . implode('; ', $errors));
                }
            }
            $installer->installCode($work . '/dolicli', $work . '/dolicli.php', $ctx->flag('force'));
            if (isset($decoded['instances.ini'])) {
                Files::ensureDir(Paths::etcDir(), 0755);
                Files::writeAtomic(Paths::instancesIni(), $decoded['instances.ini'], 0644);
            }
        } finally {
            Files::removeTree($work);
        }
    }

    public function renderText(array $data): string
    {
        return implode("\n", $data['actions']) . "\n";
    }
}
