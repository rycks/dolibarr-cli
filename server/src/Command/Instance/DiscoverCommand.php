<?php

namespace Dolicli\Command\Instance;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Cli\TextRenderer;
use Dolicli\Instance\Discovery;

final class DiscoverCommand extends Command
{
    public function name(): string
    {
        return 'instance discover';
    }

    public function summary(): string
    {
        return 'Find the Dolibarr installations of the server (conf.php parsed, never executed)';
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
            Param::value('root', 'DIR', 'Directory to search (default: /var/www)'),
            Param::value('depth', 'N', 'Maximum depth (default: 6)')->integer(),
            Param::value('timeout', 'SECONDS', 'Maximum duration (default: 30)')->integer(),
        ];
    }

    public function help(): string
    {
        return "Run as root to read conf.php files in mode 0400 before knowing run_as, or as an account that can\n"
            . "read them. Returns, per instance: htdocs, conf.php (also /etc/dolibarr/conf.php of the Debian and RPM\n"
            . 'packages), documents/ with its owner and group (the default run_as), files version, URL. Never credentials.';
    }

    public function execute(Context $ctx): array
    {
        $root = (string) ($ctx->value('root') ?: '/var/www');
        if (!is_dir($root)) {
            throw Failure::usage('INVALID_VALUE', "Directory not found: {$root}");
        }
        $discovery = new Discovery((int) ($ctx->value('depth') ?: 6), (int) ($ctx->value('timeout') ?: 30));
        $instances = $discovery->scan($root);
        $unreadable = $discovery->unreadable();
        if ([] !== $unreadable) {
            $ctx->warn(
                'DISCOVERY_UNREADABLE',
                count($unreadable) . ' directories could not be listed by this account (' . implode(', ', array_slice($unreadable, 0, 5))
                . (count($unreadable) > 5 ? ', ...' : '') . '): search as root, or give root= the installation directory or its htdocs.'
            );
        }
        if ($discovery->truncated()) {
            $ctx->warn('DISCOVERY_TRUNCATED', 'The search stopped at its time limit: some instances may be missing (timeout=SECONDS).');
        }

        return ['root' => $root, 'instances' => $instances];
    }

    public function renderText(array $data): string
    {
        $rows = [];
        foreach ($data['instances'] as $instance) {
            $rows[] = [
                'id' => $instance['id'],
                'version' => $instance['version'],
                'htdocs' => $instance['htdocs'],
                'documents' => $instance['documents'],
                'run_as' => $instance['documents_owner'],
            ];
        }

        return TextRenderer::records($rows);
    }
}
