<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Cli\TextRenderer;
use Dolicli\Dolibarr\Dolibarr;

/**
 * Sessions of the instance, from listOfSessions(): count by default, logins on request.
 * The raw content of the sessions (tokens) is never returned.
 */
final class SessionsCommand extends Command
{
    public function name(): string
    {
        return 'system sessions';
    }

    public function summary(): string
    {
        return 'Count the active sessions (logins with --details)';
    }

    public function params(): array
    {
        return [Param::flag('details', 'List the logins, ages and dates of the sessions (personal data)')];
    }

    public function help(): string
    {
        return "Reads the sess_* files of session.save_path of this PHP CLI: wrong when the web pool uses another\n"
            . "path or Redis/memcached. A directory that cannot be listed (mode 1733 on Debian) is an error, not 0.\n"
            . 'A session file stays until the garbage collector removes it: the count covers session.gc_maxlifetime.';
    }

    public function execute(Context $ctx): array
    {
        $handler = (string) ini_get('session.save_handler');
        if ('' !== $handler && 'files' !== $handler) {
            throw Failure::operation('SESSIONS_UNAVAILABLE', "Sessions stored with {$handler}: not countable from files.");
        }
        $path = (string) ini_get('session.save_path');
        $parts = explode(';', $path);
        $path = '' === $path ? '/tmp' : (string) end($parts);
        $dir = @opendir($path);
        if (false === $dir) {
            throw Failure::operation('SESSIONS_UNAVAILABLE', "Session directory {$path} cannot be listed by this account.");
        }
        closedir($dir);

        Dolibarr::requireCore('core/lib/admin.lib.php');
        $sessions = [];
        foreach (listOfSessions() as $session) {
            $sessions[] = [
                'login' => isset($session['login']) ? (string) $session['login'] : '',
                'age_seconds' => (int) $session['age'],
                'created_at' => date('c', (int) $session['creation']),
                'modified_at' => date('c', (int) $session['modification']),
            ];
        }
        $data = ['save_path' => $path, 'count' => count($sessions)];
        if ($ctx->flag('details')) {
            $data['sessions'] = $sessions;
        }

        return $data;
    }

    public function renderText(array $data): string
    {
        $out = "{$data['count']} active sessions ({$data['save_path']})\n";

        return isset($data['sessions']) ? $out . TextRenderer::records($data['sessions']) : $out;
    }
}
