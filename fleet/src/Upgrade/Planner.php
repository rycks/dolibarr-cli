<?php

declare(strict_types=1);

namespace Dolifleet\Upgrade;

use Dolicli\Php\Matrix;
use Dolicli\Upgrade\MigrationPlanner;
use Dolifleet\Inventory\Instance;
use Dolifleet\Remote\Dolicli;
use RuntimeException;

/**
 * Upgrade plan of one instance (section 7.3): preconditions, backup, new files prepared next to
 * the current ones then switched, one migration per major version with a PHP CLI compatible
 * with the target, finalization, switch of the web PHP when needed, checks, reopening.
 */
final class Planner
{
    /** Margin of free space over the sizes measured. */
    public const SPACE_MARGIN = 1.2;

    public function __construct(private readonly Dolicli $dolicli)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(Instance $instance, string $to): array
    {
        if ( ! MigrationPlanner::isVersion($to)) {
            throw new RuntimeException("Invalid target version '{$to}': expected X.Y.Z.");
        }
        $status = $this->data($instance, ['upgrade', 'status'], 'upgrade status');
        $php = $this->data($instance, ['system', 'php', 'dolibarr='.$to], 'system php');
        $modules = $this->data($instance, ['module', 'list', '--external', '--active'], 'module list');

        $from = MigrationPlanner::clean((string) $status['database_version']);
        $files = MigrationPlanner::clean((string) $status['files_version']);
        if (version_compare($to, $files, '<=')) {
            throw new RuntimeException("Target {$to} is not newer than the files {$files}.");
        }

        $blockers = [];
        $warnings = [];
        if ( ! empty($status['upgrade_pending'])) {
            $warnings[] = "The database ({$from}) is behind the files ({$files}): the migration starts from {$from}.";
        }
        foreach ((array) $status['switch_blockers'] as $blocker) {
            $blockers[] = 'Manual action: '.(string) $blocker;
        }

        // PHP CLI for the migrations: the first compatible binary, the newest last.
        $cli = '';
        foreach ((array) $php['binaries'] as $binary) {
            if (in_array($binary['compatibility'], [Matrix::OK, Matrix::WARNING], true) && in_array('json', (array) $binary['extensions'], true)) {
                $cli = (string) $binary['path'];
            }
        }
        $range = Matrix::range($to);
        if ('' === $cli) {
            $blockers[] = "No PHP CLI compatible with Dolibarr {$to} on the server (needs {$range['min']} to "
                .('' !== $range['max'] ? $range['max'] : 'unknown').'): install one next to the web PHP (manual action).';
        }

        $free = (int) ($status['free_bytes']['htdocs_parent'] ?? 0);
        $needed = (int) (max(0, (int) $status['htdocs_bytes']) * 2 * self::SPACE_MARGIN);
        if ($free > 0 && $needed > $free) {
            $blockers[] = sprintf('Not enough disk space next to htdocs: %d MiB needed (new files and archive), %d MiB free.', $needed / 1048576, $free / 1048576);
        }

        $disable = [];
        foreach ((array) $modules['modules'] as $module) {
            $need = (string) ($module['need_dolibarr_version'] ?? '');
            $disable[] = (string) $module['class'];
            $warnings[] = 'External module '.$module['class'].' '.$module['version'].': compatibility with '.$to.' '
                .('' === $need ? 'unknown' : 'unknown (it only declares a minimum, '.$need.')').', deactivated during the upgrade.';
        }

        $webPhp = $instance->webPhp;
        $phpSwitch = null;
        if ('' === $webPhp) {
            $warnings[] = 'web_php is not declared in the inventory: the compatibility of the web PHP with '.$to.' cannot be checked.';
        } elseif (Matrix::OK !== Matrix::check($to, $webPhp) && Matrix::WARNING !== Matrix::check($to, $webPhp)) {
            $phpSwitch = "Switch the PHP of the web server from {$webPhp} to a version between {$range['min']} and "
                .('' !== $range['max'] ? $range['max'] : 'the latest supported').' (extensions of Dolibarr: mysqli or pgsql, mbstring, intl, gd, zip, xml, curl).';
        }

        $migrations = self::migrations($from, $to);
        $steps = [
            ['id' => 'lease', 'type' => 'lease', 'description' => 'Take the lease of the plan on the server'],
            ['id' => 'maintenance', 'type' => 'maintenance', 'description' => 'Maintenance mode on'.('' !== $instance->maintenanceLogin ? ' (login '.$instance->maintenanceLogin.')' : '')],
            ['id' => 'cron', 'type' => 'manual', 'description' => 'Suspend the scheduled jobs of Dolibarr on the server (crontab line of scripts/cron/cron_run_jobs.php, or the HTTP trigger)'],
            ['id' => 'modules-off', 'type' => 'modules-off', 'modules' => $disable, 'description' => [] === $disable ? 'No external module to deactivate' : 'Deactivate '.implode(', ', $disable)],
            ['id' => 'backup', 'type' => 'backup', 'description' => 'Back up the database and documents/ (detached job)'],
            ['id' => 'archive', 'type' => 'archive', 'description' => "Download Dolibarr {$to} and its official signature, send them to the server"],
            ['id' => 'deploy', 'type' => 'deploy', 'description' => "Prepare htdocs.new-{$to} next to htdocs, checked against the signature"],
            ['id' => 'switch-files', 'type' => 'switch-files', 'description' => 'htdocs -> htdocs.old-<date>, htdocs.new -> htdocs'],
            ['id' => 'migration', 'type' => 'migration', 'php' => $cli, 'migrations' => $migrations, 'description' => 'Migrate the database '.implode(', ', array_map(static fn ($m) => $m['from'].' -> '.$m['to'], $migrations)).' with '.$cli],
            ['id' => 'finalize', 'type' => 'finalize', 'description' => 'step5.php, install.lock put back (also after a failure)'],
        ];
        if (null !== $phpSwitch) {
            $steps[] = ['id' => 'php-switch', 'type' => 'manual', 'description' => $phpSwitch];
        }
        $steps[] = ['id' => 'verify', 'type' => 'verify', 'description' => 'Checks: no upgrade pending, database at '.$to.', files against the signature'];
        $steps[] = ['id' => 'reopen', 'type' => 'reopen', 'description' => 'Reactivate the modules, resume the scheduled jobs, maintenance off'];

        return [
            'instance' => $instance->id,
            'from' => $from,
            'files_from' => $files,
            'to' => $to,
            'php_cli' => $cli,
            'web_php' => $webPhp,
            'created_at' => date('c'),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'irreversible' => 'Every migration step: going back means restoring the database backup.',
            'steps' => $steps,
            'progress' => [],
        ];
    }

    /**
     * Expected migration steps (the real list comes from the files of the target, on the server).
     *
     * @return list<array{from: string, to: string}>
     */
    public static function migrations(string $from, string $to): array
    {
        $steps = [];
        $major = (int) explode('.', $from)[0];
        $target = (int) explode('.', $to)[0];
        for ($m = $major; $m < $target; ++$m) {
            $steps[] = ['from' => $m.'.0.0', 'to' => ($m + 1).'.0.0'];
        }
        if ($major === $target || [] === $steps) {
            $steps[] = ['from' => $major.'.0.0', 'to' => $to];
        } elseif ($target.'.0.0' !== $to) {
            $steps[] = ['from' => $target.'.0.0', 'to' => $to];
        }

        return $steps;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function data(Instance $instance, array $arguments, string $label): array
    {
        $outcome = $this->dolicli->run($instance, $arguments);
        if ( ! $outcome->ok()) {
            throw new RuntimeException("{$label} on {$instance->id}: ".$outcome->error);
        }

        return $outcome->data();
    }
}
