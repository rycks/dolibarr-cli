<?php

namespace Dolicli\Command\System;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Backup;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Dolibarr\Maintenance;
use Dolicli\System\Files;
use Dolicli\System\Http;
use Dolicli\System\Log;
use Dolicli\System\Process;
use Dolicli\Upgrade\Deployer;
use Dolicli\Upgrade\FileSignature;
use Dolicli\Upgrade\MigrationPlanner;
use Dolicli\Upgrade\Migrator;
use Dolicli\Upgrade\Releases;
use Dolicli\Upgrade\Workspace;

/**
 * Local upgrade, all steps chained: download or local source, signature check, backup,
 * new files, migration, finalization. The pilot runs the same steps one by one
 * (dolicli upgrade deploy, switch-files, migrate, finalize).
 */
final class UpgradeCommand extends Command
{
    public function name(): string
    {
        return 'system upgrade';
    }

    public function summary(): string
    {
        return 'Upgrade Dolibarr files and/or migrate the database';
    }

    public function category(): string
    {
        return self::DESTRUCTIVE;
    }

    public function params(): array
    {
        return [
            Param::flag('check', 'Check for available upgrades'),
            Param::flag('list', 'List available versions'),
            Param::flag('minor', 'Upgrade to the latest version of the current branch'),
            Param::flag('major', 'Upgrade to the latest published version'),
            Param::value('version', 'X.Y.Z', 'Upgrade to a specific version')->matching('/^\d+\.\d+\.\d+$/'),
            Param::value('source', 'PATH', 'Local directory instead of downloading (an htdocs or a directory containing htdocs/), needs version='),
            Param::flag('force', 'Run the migration again even if the database looks up to date'),
            Param::value('from', 'X.Y.Z', 'Version the database comes from (default: MAIN_VERSION_LAST_UPGRADE)')->matching('/^\d+\.\d+\.\d+/'),
            Param::flag('skip-backup', 'Do not dump the database (nor copy the files in place mode) before upgrading'),
            Param::value('backup-dir', 'PATH', 'Where backups go (default: the directory containing htdocs/)'),
            Param::value('keep-backups', 'N', 'After a successful backup, delete older dolicli backups beyond the N most recent')->integer(),
            Param::flag('skip-verify', 'Do not check the new files against the official signature (dolibarr.org)'),
        ];
    }

    public function help(): string
    {
        return "Without --minor, --major or version=, only the database is migrated (files already updated).\n"
            . "Full upgrade: the new files are prepared next to htdocs and switched by rename (htdocs.old-<date>\n"
            . "keeps the previous ones); when the directory containing htdocs is not writable, they are copied\n"
            . "in place with rsync. A jump over several major versions is migrated one major version at a time.\n"
            . "The database is dumped before migration unless --skip-backup. The maintenance mode is on during\n"
            . 'the upgrade; after a failure it stays on, and install.lock is always put back.';
    }

    public function examples(): array
    {
        return [
            'system upgrade --check',
            'system upgrade --minor',
            'system upgrade version=22.0.0',
            'system upgrade version=18.0.10 source=/srv/dolibarr-18.0.10 --yes',
            'system upgrade from=17.0.4',
        ];
    }

    public function execute(Context $ctx): array
    {
        if ($ctx->flag('check')) {
            $versions = Releases::all();
            $current = MigrationPlanner::clean(DOL_VERSION);
            $minor = Releases::latestMinor($current, $versions);
            $latest = Releases::latest($versions);

            return [
                'current' => $current,
                'latest_minor' => '' !== $minor && version_compare($minor, $current, '>') ? $minor : null,
                'latest' => '' !== $latest && version_compare($latest, $current, '>') ? $latest : null,
            ];
        }
        if ($ctx->flag('list')) {
            return ['versions' => array_reverse(array_slice(Releases::all(), -20))];
        }

        if (null !== $ctx->value('source') && null === $ctx->value('version')) {
            throw Failure::usage('MISSING_PARAMETER', 'source= requires version=X.Y.Z.');
        }
        $target = (string) $ctx->value('version');
        if ('' === $target && ($ctx->flag('minor') || $ctx->flag('major'))) {
            $versions = Releases::all();
            $target = $ctx->flag('minor') ? Releases::latestMinor(DOL_VERSION, $versions) : Releases::latest($versions);
            if ('' === $target) {
                throw Failure::operation('NO_TARGET_VERSION', 'Could not find the target version on dolibarr.org.');
            }
        }

        return '' === $target ? $this->databaseOnly($ctx) : $this->fullUpgrade($ctx, $target);
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseOnly(Context $ctx): array
    {
        $from = $this->databaseVersion($ctx);
        $to = MigrationPlanner::clean(DOL_VERSION);
        $steps = Migrator::plan(DOL_DOCUMENT_ROOT, $from, $to, $ctx->flag('force'));
        if ([] === $steps) {
            return ['mode' => 'database', 'from' => $from, 'to' => $to, 'steps' => [], 'message' => "Database is already at version {$from}: nothing to migrate (use --force to run it again)."];
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => $this->migrationActions($ctx, $steps)];
        }

        $backup = $ctx->flag('skip-backup') ? '' : $this->dumpDatabase($ctx, $from);
        $maintenance = $this->enableMaintenance($ctx);
        $this->migrateAndFinalize($ctx, $from, $to, $backup);
        $this->disableMaintenance($ctx, $maintenance);

        return ['mode' => 'database', 'from' => $from, 'to' => $to, 'steps' => $steps, 'backup' => $backup, 'message' => "Database migrated from {$from} to {$to}."];
    }

    /**
     * @return array<string, mixed>
     */
    private function fullUpgrade(Context $ctx, string $target): array
    {
        $current = MigrationPlanner::clean(DOL_VERSION);
        if (version_compare($target, $current, '<=')) {
            return ['mode' => 'full', 'from' => $current, 'to' => $target, 'steps' => [], 'message' => "Target version {$target} is not newer than the current version {$current}: no upgrade needed."];
        }
        $from = $this->databaseVersion($ctx);
        $deployer = Deployer::current();
        $inPlace = [] !== $deployer->switchBlockers();
        $source = (string) $ctx->value('source');
        if ($inPlace && '' === Process::find(['rsync'])) {
            throw Failure::environment('TOOL_MISSING', 'rsync not found, and the new files cannot be switched: ' . implode('; ', $deployer->switchBlockers()) . '.');
        }

        if ($ctx->dryRun()) {
            $actions = [
                ('' !== $source ? "Use the local source {$source}" : "Download Dolibarr {$target} from dolibarr.org"),
                $ctx->flag('skip-verify') ? 'Do not check the signature' : 'Check the new files against the official signature',
                $ctx->flag('skip-backup') ? 'No backup' : 'Dump the database' . ($inPlace ? ' and copy the current files' : ''),
                'Enable the maintenance mode',
                $inPlace ? 'Copy the new files in place with rsync (' . implode('; ', $deployer->switchBlockers()) . ')' : "Prepare htdocs.new-{$target}, then switch it with htdocs",
            ];

            return ['dry_run' => true, 'actions' => array_merge($actions, ["Migrate the database from {$from} to {$target}", 'Finalize (step5.php, install.lock)', 'Disable the maintenance mode'])];
        }

        if (!$ctx->confirm("Upgrade Dolibarr from {$current} to {$target}?")) {
            throw Failure::operation('CANCELLED', 'Upgrade cancelled, nothing was changed.');
        }

        $workspace = Workspace::current();
        $workspace->ensure();
        $work = Files::tempDir($workspace->dir());
        $ctx->defer(function () use ($work) {
            Files::removeTree($work);
        });

        // Everything that can fail without touching the installation comes first.
        if ('' === $source) {
            $source = $this->download($ctx, $target, $work);
        }
        $signature = '';
        if (!$ctx->flag('skip-verify')) {
            $url = sprintf(FileSignature::REMOTE_URL, $target);
            $xml = Http::get($url, 60);
            if (null === $xml) {
                throw Failure::operation('SIGNATURE_NOT_FOUND', "Cannot download the official signature {$url}: " . Http::lastError() . ' (--skip-verify to bypass).');
            }
            $signature = $work . '/filelist-' . $target . '.xml';
            file_put_contents($signature, $xml);
        }
        $staged = '';
        if (!$inPlace) {
            $ctx->progress("Preparing Dolibarr {$target} next to the current files");
            $result = $deployer->stage($source, $target, $signature, function ($line) use ($ctx) {
                $ctx->progress($line);
            });
            $staged = $result['staged'];
            if (!$result['owner']['kept']) {
                $ctx->warn('OWNER_CHANGED', $result['owner']['message']);
            }
        } elseif (!is_dir($source)) {
            $extracted = $work . '/extracted';
            Files::ensureDir($extracted, 0700);
            $this->run(1 === preg_match('/\.zip$/', $source) ? ['unzip', '-q', $source, '-d', $extracted] : ['tar', '-xzf', $source, '-C', $extracted], 'Extraction failed');
            $source = $extracted;
        }

        $backup = '';
        if (!$ctx->flag('skip-backup')) {
            $backup = $this->dumpDatabase($ctx, $from);
            if ($inPlace) {
                $ctx->progress('Copying the current files to ' . $backup);
                $this->run(['cp', '-a', DOL_DOCUMENT_ROOT, $backup . '/htdocs'], 'File backup failed');
            }
        }

        $maintenance = $this->enableMaintenance($ctx);
        $previous = '';
        if ($inPlace) {
            $tree = is_file($source . '/main.inc.php') ? $source : (is_file($source . '/htdocs/main.inc.php') ? $source . '/htdocs' : $source . "/dolibarr-{$target}/htdocs");
            if (!is_file($tree . '/main.inc.php')) {
                throw Failure::operation('NO_HTDOCS', "No Dolibarr htdocs found in {$source}.");
            }
            if ('' !== $signature) {
                $deployer->verify($tree, $signature);
            }
            $ctx->progress('Copying the new files in place');
            $this->run(['rsync', '-a', '--exclude=/documents', '--exclude=/conf/conf.php', '--exclude=/custom', rtrim($tree, '/') . '/', DOL_DOCUMENT_ROOT . '/'], 'File copy failed');
        } else {
            $switched = $deployer->switchTo($target);
            $previous = $switched['previous'];
            $ctx->progress('New files in place, previous ones in ' . $previous);
        }

        $this->migrateAndFinalize($ctx, $from, $target, $backup, $previous);
        $this->disableMaintenance($ctx, $maintenance);

        return [
            'mode' => $inPlace ? 'in-place' : 'switch',
            'from' => $current,
            'to' => $target,
            'staged' => $staged,
            'previous_files' => $previous,
            'backup' => $backup,
            'message' => "Dolibarr upgraded from {$current} to {$target}.",
        ];
    }

    /**
     * Migration and finalization; after a failure the maintenance mode stays on and the
     * recovery steps are given.
     */
    private function migrateAndFinalize(Context $ctx, string $from, string $to, string $backup, string $previous = '')
    {
        $workspace = Workspace::current();
        $migrator = new Migrator(function ($text) use ($ctx) {
            $ctx->progress(rtrim($text, "\n"));
        }, $workspace);
        try {
            $migrator->migrate(DOL_DOCUMENT_ROOT, $from, $to, $ctx->flag('force'));
            $migrator->finalize(DOL_DOCUMENT_ROOT, $from, $to);
        } catch (Failure $failure) {
            $recovery = [];
            if ('' !== $backup) {
                $recovery[] = 'database backup: ' . $backup;
            }
            if ('' !== $previous) {
                $recovery[] = 'previous files: ' . $previous . ' (dolicli upgrade restore-files)';
            }
            $recovery[] = 'the maintenance mode stays on (dolicli system maintenance disable)';
            Log::error('upgrade failed: ' . $failure->getMessage());
            throw Failure::operation('UPGRADE_FAILED', $failure->getMessage() . ' Recovery: ' . implode('; ', $recovery) . '.', ['recovery' => $recovery]);
        }
    }

    private function databaseVersion(Context $ctx): string
    {
        $from = (string) $ctx->value('from');
        if ('' === $from) {
            $from = Dolibarr::databaseVersion();
        }
        if (!MigrationPlanner::isVersion($from)) {
            throw Failure::operation('UNKNOWN_DATABASE_VERSION', 'Cannot tell which version the database comes from (MAIN_VERSION_LAST_UPGRADE is empty): give it with from=X.Y.Z.');
        }

        return MigrationPlanner::clean($from);
    }

    /**
     * @param array<int, array{from: string, to: string}> $steps
     * @return array<int, string>
     */
    private function migrationActions(Context $ctx, array $steps): array
    {
        $actions = $ctx->flag('skip-backup') ? ['No backup'] : ['Dump the database to ' . $this->backupParent($ctx)];
        $actions[] = 'Enable the maintenance mode';
        foreach ($steps as $step) {
            $actions[] = "Migrate {$step['from']} -> {$step['to']} (upgrade.php, upgrade2.php)";
        }
        $actions[] = 'Finalize (step5.php, install.lock)';
        $actions[] = 'Disable the maintenance mode';

        return $actions;
    }

    private function backupParent(Context $ctx): string
    {
        return rtrim((string) ($ctx->value('backup-dir') ?: dirname(DOL_DOCUMENT_ROOT)), '/');
    }

    /**
     * Dump the database in <backup-dir>/dolibarr_backup_<from>_<date>/ and rotate old backups.
     */
    private function dumpDatabase(Context $ctx, string $from): string
    {
        $parent = $this->backupParent($ctx);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw Failure::operation('BACKUP_DIR_UNAVAILABLE', "Backup directory is not writable: {$parent} (use backup-dir=PATH or --skip-backup).");
        }
        $dir = $parent . '/dolibarr_backup_' . $from . '_' . date('Ymd_His');
        Files::ensureDir($dir, 0700);
        $ctx->progress('Dumping the database to ' . $dir . '/database.sql');
        $backup = new Backup();
        if (!$backup->dumpDatabase($dir . '/database.sql')) {
            throw Failure::operation('BACKUP_FAILED', 'Database backup failed: ' . $backup->lastError() . ' (use --skip-backup to bypass).');
        }
        $this->rotateBackups($ctx, $parent, $dir);

        return $dir;
    }

    private function rotateBackups(Context $ctx, string $parent, string $current)
    {
        $keep = $ctx->value('keep-backups');
        if (null === $keep) {
            return;
        }
        $keep = max(1, (int) $keep);
        $all = [];
        $entries = @scandir($parent);
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (1 === preg_match('/^dolibarr_backup_[0-9.]+_(\d{8}_\d{6})$/', $entry, $m) && is_dir($parent . '/' . $entry)) {
                $all[$parent . '/' . $entry] = $m[1];
            }
        }
        arsort($all);
        foreach (array_slice(array_keys($all), $keep) as $old) {
            if ($old !== $current) {
                $ctx->progress("Removing old backup {$old} (keep-backups={$keep})");
                Files::removeTree($old);
            }
        }
    }

    private function enableMaintenance(Context $ctx): Maintenance
    {
        $maintenance = new Maintenance();
        $login = $maintenance->enable('Temporary maintenance for upgrade', $ctx->instance()->setting('maintenance_login'));
        if ('' === $login) {
            throw Failure::operation('MAINTENANCE_FAILED', $maintenance->lastError());
        }
        $ctx->progress("Maintenance mode on (only {$login} can log in)");

        return $maintenance;
    }

    private function disableMaintenance(Context $ctx, Maintenance $maintenance)
    {
        if ($maintenance->wasEnabledByUs() && !$maintenance->disable()) {
            $ctx->warn('MAINTENANCE_STILL_ON', 'Cannot disable the maintenance mode: run dolicli system maintenance disable.');
        }
    }

    private function download(Context $ctx, string $version, string $dir): string
    {
        foreach (Releases::archiveUrls($version) as $url) {
            $target = $dir . '/' . basename($url);
            $ctx->progress("Downloading {$url}");
            if (Http::download($url, $target, 600)) {
                return $target;
            }
        }
        throw Failure::operation('DOWNLOAD_FAILED', "Download of Dolibarr {$version} failed: " . Http::lastError());
    }

    /**
     * @param array<int, string> $command
     */
    private function run(array $command, string $error)
    {
        $process = new Process();
        if (0 !== $process->run($command)) {
            throw Failure::operation('UPGRADE_FAILED', $error . ': ' . $command[0] . ' exited with code ' . $process->exitCode() . ' (' . $process->error() . ')');
        }
    }

    public function renderText(array $data): string
    {
        if (array_key_exists('latest_minor', $data)) {
            return "Current version: {$data['current']}\n"
                . (null !== $data['latest_minor'] ? "Minor upgrade available: {$data['latest_minor']} (dolicli system upgrade --minor)\n" : "No minor upgrade available.\n")
                . (null !== $data['latest'] ? "Latest version: {$data['latest']} (dolicli system upgrade --major)\n" : '');
        }
        if (isset($data['versions'])) {
            return "Available Dolibarr versions:\n  * " . implode("\n  * ", $data['versions']) . "\n";
        }
        $out = $data['message'] . "\n";
        if (!empty($data['backup'])) {
            $out .= "Backup: {$data['backup']}\n";
        }
        if (!empty($data['previous_files'])) {
            $out .= "Previous files: {$data['previous_files']}\n";
        }

        return $out;
    }
}
