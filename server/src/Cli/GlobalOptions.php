<?php

namespace Dolicli\Cli;

/**
 * Options accepted by every command (contract 4.6).
 */
final class GlobalOptions
{
    const FORMATS = ['text', 'json', 'prometheus', 'nagios'];

    /**
     * @return array<int, Param>
     */
    public static function all(): array
    {
        return [
            Param::value('format', 'FORMAT', 'Output format: text by default on a terminal, json otherwise; prometheus and nagios for monitor')
                ->choices(self::FORMATS),
            Param::value('instance', 'ID', 'Instance declared in /etc/dolicli/instances.ini (installed mode)'),
            Param::value('dolpath', 'HTDOCS', 'Dolibarr htdocs directory (default: DOLPATH, then ./htdocs)'),
            Param::value('entity', 'N', 'Dolibarr entity (multicompany), 1 by default')->integer(),
            Param::value('as', 'LOGIN', 'Dolibarr user acting for the command (default: dolibarr_user, then first super admin)'),
            Param::flag('yes', 'Answer yes to confirmations (required without a terminal)'),
            Param::flag('dry-run', 'Describe the actions without doing them'),
            Param::flag('quiet', 'No progress messages'),
            Param::flag('show-secrets', 'Display secrets (cron key, tokens) instead of masking them'),
            Param::flag('allow-root', 'Allow a command that loads Dolibarr to run as root'),
            Param::flag('help', 'Display the help of the command'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::all() as $param) {
            $names[] = $param->name();
        }

        return $names;
    }
}
