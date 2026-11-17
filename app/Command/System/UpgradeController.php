<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Support\Command;
use App\Support\ExitStatus;
use App\Util\DatabaseBackup;
use App\Util\DolibarrHelper;
use App\Util\FileSignature;
use App\Util\Http;
use App\Util\InstallLock;
use App\Util\InstallScriptRunner;
use App\Util\MigrationPlanner;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class UpgradeController extends Command
{
    private const VERSIONS_URL = 'https://www.dolibarr.org/files/stable/standard/';

    private const DOWNLOAD_URL = 'https://www.dolibarr.org/files/stable/standard/dolibarr-%s.%s';

    /** @var array{uid: int, gid: int, user: string, group: string}|null */
    private ?array $pendingOwnership = null;

    /** @var array{uid: int, gid: int, user: string, group: string}|null */
    private ?array $pendingDataOwnership = null;

    private bool $ownershipRestorationDone = false;

    /** File whose mtime marks the start of the upgrade (files newer than it get their owner back). */
    private ?string $startMarker = null;

    /** Private working directory (download, extraction), removed at the end. */
    private ?string $workDir = null;

    private ?InstallLock $installLock = null;

    private bool $maintenanceEnabledByUs = false;

    public function usage(): void
    {
        $this->info('Dolibarr Upgrade Manager - Update files and migrate database

Usage:
  ./dolibarr system upgrade                        - Run database migration only
  ./dolibarr system upgrade --minor                - Upgrade to latest version of the current branch
  ./dolibarr system upgrade --major                - Upgrade to latest published version
  ./dolibarr system upgrade --version=X.Y.Z        - Upgrade to specific version
  ./dolibarr system upgrade --check                - Check for available upgrades
  ./dolibarr system upgrade --list                 - List available versions

Options:
  --force            - Run the migration again even if the database looks up to date
  from=X.Y.Z         - Version the database comes from (default: MAIN_VERSION_LAST_UPGRADE)
  --skip-backup      - Do not back up the files nor dump the database before upgrading
  backup-dir=PATH    - Where backups go (default: the directory containing htdocs/)
  keep-backups=N     - After a successful backup, delete older dolicli backups beyond the N most recent
  --skip-verify      - Do not check the new files against the official signature (dolibarr.org)
  --source=PATH      - Use a local directory instead of downloading (skips download + extract).
                       PATH can point either to a Dolibarr htdocs/ directory or to a
                       directory containing htdocs/ (auto-detected).
  --yes              - Non-interactive mode: answer yes to every confirmation
  --help             - Display this help message

Exit codes:
  0 - success
  1 - failure (for shell scripts looping over multiple installations)

Database migration only (when files already updated):
  ./dolibarr system upgrade
  ./dolibarr system upgrade from=17.0.4

Full upgrade (download + verification + backup + files + database):
  ./dolibarr system upgrade --check
  ./dolibarr system upgrade --minor
  ./dolibarr system upgrade --version=22.0.0

Batch upgrade with local source (no download, no prompts):
  ./dolibarr system upgrade --version=18.0.10 --source=/srv/dolibarr-18.0.10 --yes

Notes:
- A jump over several major versions is migrated one major version at a time,
  like the web installer does.
- The database is dumped before migration unless --skip-backup is given.
- Maintenance mode is enabled during the migration, then disabled.');
    }

    public function handle(): void
    {
        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ($this->hasFlag('check')) {
            $currentVersion = DOL_VERSION;
            $this->info("Current Dolibarr version: {$currentVersion}\n");
            $this->checkForUpgrades($currentVersion);

            return;
        }

        if ($this->hasFlag('list')) {
            $this->listAvailableVersions();

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        if ($this->hasOption('source') && ! $this->hasOption('version')) {
            $this->fail('--source requires --version=X.Y.Z to be specified explicitly.');

            return;
        }

        $targetVersion = null;
        if ($this->hasOption('version')) {
            $targetVersion = (string) $this->param('version');
            if ( ! MigrationPlanner::isVersion($targetVersion)) {
                $this->fail("Invalid version '{$targetVersion}': expected X.Y.Z.");

                return;
            }
        } elseif ($this->hasFlag('minor') || $this->hasFlag('major')) {
            $targetVersion = $this->hasFlag('minor')
                ? $this->getLatestMinorVersion(DOL_VERSION)
                : $this->getLatestMajorVersion();
            if (null === $targetVersion) {
                $this->fail('Could not find the target version on dolibarr.org: '.Http::lastError());

                return;
            }
        }

        if (null !== $targetVersion) {
            $this->performFullUpgrade($targetVersion);
        } else {
            $this->performDatabaseOnlyMigration();
        }
    }

    // ==================== VERSION MANAGEMENT ====================

    private function checkForUpgrades(string $currentVersion): void
    {
        $this->info("Checking for available upgrades...\n");

        $versions = $this->fetchAvailableVersions();
        if ([] === $versions) {
            $this->fail('Could not fetch versions from dolibarr.org: '.Http::lastError());

            return;
        }

        $latestMinor = $this->getLatestMinorVersion($currentVersion, $versions);
        if (null !== $latestMinor && version_compare($latestMinor, $currentVersion, '>')) {
            $this->success("Minor upgrade available: {$latestMinor}");
            $this->info("Run: ./dolibarr system upgrade --minor\n");
        } else {
            $this->info("No minor upgrades available for current major version.\n");
        }

        $latestMajor = $this->getLatestMajorVersion($versions);
        if (null !== $latestMajor && version_compare($latestMajor, $currentVersion, '>')) {
            $this->success("Major upgrade available: {$latestMajor}");
            $this->info("Run: ./dolibarr system upgrade --major\n");
        }
    }

    private function listAvailableVersions(): void
    {
        $this->info("Fetching available versions from dolibarr.org...\n");

        $versions = $this->fetchAvailableVersions();
        if ([] === $versions) {
            $this->fail('Could not fetch versions from dolibarr.org: '.Http::lastError());

            return;
        }

        $this->rawOutput("Available Dolibarr versions:\n");
        $this->rawOutput("----------------------------\n");
        foreach (array_reverse(array_slice($versions, -20)) as $version) {
            $this->rawOutput('  * '.$version."\n");
        }
    }

    /**
     * @param  array<int, string>|null  $versions
     */
    private function getLatestMinorVersion(string $currentVersion, ?array $versions = null): ?string
    {
        $versions ??= $this->fetchAvailableVersions();
        $sameBranch = array_values(array_filter(
            $versions,
            static fn (string $v): bool => MigrationPlanner::sameBranch($v, $currentVersion)
        ));

        return [] === $sameBranch ? null : end($sameBranch);
    }

    /**
     * @param  array<int, string>|null  $versions
     */
    private function getLatestMajorVersion(?array $versions = null): ?string
    {
        $versions ??= $this->fetchAvailableVersions();

        return [] === $versions ? null : end($versions);
    }

    /**
     * @return array<int, string> Published versions, sorted ascending
     */
    private function fetchAvailableVersions(): array
    {
        $html = Http::get(self::VERSIONS_URL);
        if (null === $html) {
            return [];
        }

        preg_match_all('/dolibarr-(\d+\.\d+\.\d+)\.(?:tgz|zip)/', $html, $matches);
        $versions = array_values(array_unique($matches[1]));
        usort($versions, 'version_compare');

        return $versions;
    }

    // ==================== FULL UPGRADE ====================

    private function performFullUpgrade(string $toVersion): void
    {
        $fromVersion = DOL_VERSION;
        $useLocalSource = $this->hasOption('source');

        $this->rawOutput("\n=== DOLIBARR FULL UPGRADE ===\n\n");
        $this->info("Current version: {$fromVersion}");
        $this->info("Target version:  {$toVersion}");

        if (version_compare($toVersion, MigrationPlanner::clean($fromVersion), '<=')) {
            $this->info("Target version ({$toVersion}) is not newer than current version ({$fromVersion}): no upgrade needed.");

            return;
        }

        // Validate everything that can be validated before any destructive operation.
        $resolvedSource = null;
        if ($useLocalSource) {
            $resolvedSource = $this->resolveLocalSource((string) $this->param('source'));
            if (null === $resolvedSource) {
                $this->fail('Invalid --source path: '.$this->param('source').' (expected a Dolibarr htdocs/ directory or a directory containing htdocs/).');

                return;
            }
            $this->info("Source:          {$resolvedSource} (local)");
        }
        $backupParent = $this->backupParentDir();
        if (null === $backupParent) {
            return;
        }
        if ( ! $this->hasFlag('skip-backup') && ! $this->checkBackupSpace($backupParent)) {
            return;
        }
        foreach (['rsync', 'cp'] as $tool) {
            if (null === $this->findBinary($tool)) {
                $this->fail("{$tool} not found in PATH.");

                return;
            }
        }

        $this->rawOutput("\n");
        if ( ! $this->confirm("Upgrade Dolibarr from {$fromVersion} to {$toVersion}?")) {
            if (ExitStatus::SUCCESS === ExitStatus::get()) {
                $this->info('Upgrade cancelled by user.');
            }

            return;
        }

        $this->prepareOwnershipRestore();
        register_shutdown_function([$this, 'runShutdownCleanup']);

        $backupPath = null;

        try {
            $this->workDir = $this->createWorkDir();

            // Step 1: get the new files
            if ($useLocalSource) {
                $this->rawOutput("Step 1/6: Using local source {$resolvedSource}\n");
                $sourcePath = (string) $resolvedSource;
            } else {
                $this->rawOutput("Step 1/6: Downloading Dolibarr {$toVersion}...\n");
                $sourcePath = $this->downloadAndExtract($toVersion);
            }

            // Step 2: check them against the official signature
            if ($this->hasFlag('skip-verify')) {
                $this->rawOutput("Step 2/6: Skipping signature check (--skip-verify)\n");
            } else {
                $this->rawOutput("Step 2/6: Checking files against the official signature...\n");
                $this->verifySignature($sourcePath, $toVersion);
                $this->success("Files match the official signature of {$toVersion}\n");
            }

            // Step 3: backup
            if ($this->hasFlag('skip-backup')) {
                $this->rawOutput("Step 3/6: Skipping backup (--skip-backup)\n");
            } else {
                $this->rawOutput("Step 3/6: Backing up current installation and database...\n");
                $backupPath = $this->backupInstallation($backupParent, $fromVersion);
                $this->success("Backup created: {$backupPath}\n");
            }

            // Step 4: copy
            $this->rawOutput("Step 4/6: Copying new files...\n");
            $this->copyNewFiles($sourcePath);
            $this->success("Files copied\n");

            // Step 5: database
            $this->rawOutput("Step 5/6: Running database migration...\n");
            if ( ! $this->migrateDatabase($toVersion, $this->lastUpgradeVersion())) {
                throw new \RuntimeException('Database migration failed');
            }

            // Step 6: cleanup
            $this->rawOutput("Step 6/6: Cleaning up temporary files...\n");
            $this->removeWorkDir();
            $this->doOwnershipRestore();

            $this->rawOutput("\n");
            $this->success('Upgrade completed successfully!');
            $this->info("Dolibarr has been upgraded from {$fromVersion} to {$toVersion}\n");
        } catch (Throwable $e) {
            $this->fail('Upgrade failed: '.$e->getMessage());
            if (null !== $backupPath) {
                $this->info("Backup of files and database: {$backupPath} (restore it manually if needed).");
            }
            $this->removeWorkDir();
            $this->doOwnershipRestore();
        }
    }

    private function downloadAndExtract(string $version): string
    {
        $workDir = (string) $this->workDir;

        foreach (['tgz', 'zip'] as $ext) {
            $url = sprintf(self::DOWNLOAD_URL, $version, $ext);
            $archive = $workDir."/dolibarr-{$version}.{$ext}";
            $this->info("Downloading from: {$url}");
            if ( ! Http::download($url, $archive)) {
                $this->info('  not available: '.Http::lastError());

                continue;
            }
            $this->success('Downloaded ('.round((int) filesize($archive) / 1048576, 1).' MB)');

            $extractDir = $workDir.'/extracted';
            mkdir($extractDir, 0700);
            $command = 'tgz' === $ext
                ? ['tar', '-xzf', $archive, '-C', $extractDir]
                : ['unzip', '-q', $archive, '-d', $extractDir];
            $this->runTool($command, 'Extraction failed');

            foreach ([$extractDir."/dolibarr-{$version}/htdocs", $extractDir.'/htdocs', $extractDir."/dolibarr-{$version}"] as $candidate) {
                if (is_file($candidate.'/main.inc.php')) {
                    return $candidate;
                }
            }

            throw new \RuntimeException("No htdocs/ directory found in the archive of {$version}");
        }

        throw new \RuntimeException("Download of Dolibarr {$version} failed: ".Http::lastError());
    }

    private function verifySignature(string $sourcePath, string $version): void
    {
        $url = sprintf(FileSignature::REMOTE_URL, $version);
        $xml = Http::get($url, 60);
        if (null === $xml) {
            throw new \RuntimeException("Cannot download the official signature {$url}: ".Http::lastError().' (use --skip-verify to bypass)');
        }
        $root = FileSignature::parse($xml);
        if (null === $root) {
            throw new \RuntimeException("Invalid signature file {$url}");
        }

        $result = FileSignature::compare($root, $sourcePath);
        $problems = array_merge(
            array_map(static fn (array $f): string => 'missing  '.$f['filename'], $result['missing']),
            array_map(static fn (array $f): string => 'modified '.$f['filename'], $result['updated'])
        );
        if ([] !== $problems) {
            foreach (array_slice($problems, 0, 20) as $line) {
                $this->error('  '.$line);
            }
            if (count($problems) > 20) {
                $this->error('  ... and '.(count($problems) - 20).' more');
            }

            throw new \RuntimeException(count($problems)." file(s) differ from the official signature of {$version}: upgrade aborted, nothing was changed");
        }
    }

    private function backupParentDir(): ?string
    {
        $dir = rtrim($this->param('backup-dir') ?? dirname(DOL_DOCUMENT_ROOT), '/');
        if ($this->hasFlag('skip-backup')) {
            return $dir;
        }
        if ( ! is_dir($dir) || ! is_writable($dir)) {
            $this->fail("Backup directory is not writable: {$dir} (use backup-dir=PATH or --skip-backup).");

            return null;
        }

        return $dir;
    }

    private function checkBackupSpace(string $dir): bool
    {
        $needed = (int) ($this->directorySize(DOL_DOCUMENT_ROOT) * 1.1);
        $free = disk_free_space($dir);
        if (false !== $free && $free < $needed) {
            $this->fail(sprintf(
                'Not enough disk space in %s for the backup: %d MB needed, %d MB free (use backup-dir=PATH or --skip-backup).',
                $dir,
                (int) ($needed / 1048576),
                (int) ($free / 1048576)
            ));

            return false;
        }

        return true;
    }

    private function backupInstallation(string $parent, string $version): string
    {
        $backupDir = $parent.'/dolibarr_backup_'.$version.'_'.date('Ymd_His');
        if ( ! mkdir($backupDir, 0700)) {
            throw new \RuntimeException("Cannot create backup directory {$backupDir}");
        }

        $this->runTool(['cp', '-a', DOL_DOCUMENT_ROOT, $backupDir.'/htdocs'], 'File backup failed');

        $backup = new DatabaseBackup();
        if ( ! $backup->dumpDatabase($backupDir.'/database.sql')) {
            throw new \RuntimeException('Database backup failed: '.$backup->getLastError());
        }

        $this->rotateBackups($parent, $backupDir);

        return $backupDir;
    }

    /**
     * Keep only the N most recent dolicli backups when keep-backups=N is given.
     */
    private function rotateBackups(string $parent, string $current): void
    {
        $keep = $this->param('keep-backups');
        if (null === $keep) {
            return;
        }
        $keep = max(1, (int) $keep);

        $all = array_values(array_filter(
            (array) glob($parent.'/dolibarr_backup_*', GLOB_ONLYDIR),
            static fn ($d): bool => is_string($d) && 1 === preg_match('/\/dolibarr_backup_[0-9.]+_\d{8}_\d{6}$/', $d)
        ));
        usort($all, static fn (string $a, string $b): int => mb_substr($b, -15) <=> mb_substr($a, -15));

        foreach (array_slice($all, $keep) as $old) {
            if ($old === $current) {
                continue;
            }
            $this->info("Removing old backup {$old} (keep-backups={$keep})");
            $this->runTool(['rm', '-rf', '--', $old], "Cannot remove old backup {$old}");
        }
    }

    private function copyNewFiles(string $sourcePath): void
    {
        $this->runTool(
            ['rsync', '-a', '--exclude=/documents', '--exclude=/conf/conf.php', '--exclude=/custom', rtrim($sourcePath, '/').'/', DOL_DOCUMENT_ROOT.'/'],
            'File copy failed'
        );
    }

    // ==================== DATABASE MIGRATION ====================

    private function performDatabaseOnlyMigration(): void
    {
        $this->rawOutput("\n=== DATABASE MIGRATION ===\n\n");

        $from = $this->lastUpgradeVersion();
        if (null === $from) {
            return;
        }

        if ( ! $this->hasFlag('skip-backup')) {
            $parent = $this->backupParentDir();
            if (null === $parent) {
                return;
            }
            $file = $parent.'/dolibarr_backup_db_'.MigrationPlanner::clean($from).'_'.date('Ymd_His').'.sql';
            $this->info('Dumping database to '.$file.' ...');
            $backup = new DatabaseBackup();
            if ( ! $backup->dumpDatabase($file)) {
                $this->fail('Database backup failed: '.$backup->getLastError().' (use --skip-backup to bypass)');

                return;
            }
            $this->success('Database dumped');
        }

        register_shutdown_function([$this, 'runShutdownCleanup']);
        $this->migrateDatabase(DOL_VERSION, $from);
    }

    /**
     * Version the database was last migrated to: from=X.Y.Z, else MAIN_VERSION_LAST_UPGRADE,
     * else MAIN_VERSION_LAST_INSTALL.
     */
    private function lastUpgradeVersion(): ?string
    {
        $from = $this->param('from')
            ?? (DolibarrHelper::conf('MAIN_VERSION_LAST_UPGRADE') ?: DolibarrHelper::conf('MAIN_VERSION_LAST_INSTALL'));
        if ( ! MigrationPlanner::isVersion((string) $from)) {
            $this->fail('Cannot tell which version the database comes from (MAIN_VERSION_LAST_UPGRADE is empty): give it with from=X.Y.Z.');

            return null;
        }

        return MigrationPlanner::clean((string) $from);
    }

    private function migrateDatabase(string $targetVersion, string $fromVersion): bool
    {
        global $db, $conf;

        $installDir = DOL_DOCUMENT_ROOT.'/install';
        foreach (['upgrade.php', 'upgrade2.php', 'step5.php'] as $script) {
            if ( ! is_file($installDir.'/'.$script)) {
                $this->fail("Upgrade file not found: {$installDir}/{$script}");

                return false;
            }
        }

        $steps = MigrationPlanner::plan(
            MigrationPlanner::migrationFiles($installDir.'/mysql/migration'),
            $fromVersion,
            $targetVersion,
            $this->hasFlag('force')
        );
        if ([] === $steps) {
            $this->success("Database is already at version {$fromVersion}: nothing to migrate (use --force to run it again).");

            return true;
        }

        $this->info("Database version: {$fromVersion}, files version: {$targetVersion}");
        $this->info('Migration plan:');
        foreach ($steps as $step) {
            $this->rawOutput("  * {$step['from']} -> {$step['to']}\n");
        }
        $this->rawOutput("\n");

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
        if ( ! $this->enableMaintenance('Temporary maintenance for upgrade')) {
            return false;
        }

        $this->installLock = InstallLock::forCurrentInstall();
        $this->installLock->open();

        $runner = new InstallScriptRunner(fn (string $text) => $this->rawOutput($text));
        $ok = false;

        try {
            $total = count($steps);
            foreach ($steps as $i => $step) {
                $n = $i + 1;
                $this->rawOutput("Step {$n}/{$total}: SQL migration {$step['from']} -> {$step['to']}\n");
                $args = [$step['from'], $step['to']];
                if ($this->hasFlag('force')) {
                    $args[] = 'ignoredbversion';
                }
                if (0 !== $runner->run($installDir, 'upgrade.php', $args)) {
                    $this->reportScriptFailure('upgrade.php', $runner);

                    return false;
                }

                $this->rawOutput("Step {$n}/{$total}: data migration {$step['from']} -> {$step['to']}\n");
                if (0 !== $runner->run($installDir, 'upgrade2.php', [$step['from'], $step['to']])) {
                    $this->reportScriptFailure('upgrade2.php', $runner);

                    return false;
                }
            }

            // action must be 'upgrade' (not 'set', which is for fresh installs and does not update
            // MAIN_VERSION_LAST_UPGRADE); installlock=1 makes step5 create install.lock.
            $this->rawOutput("Finalizing migration...\n");
            $lang = DolibarrHelper::conf('MAIN_LANG_DEFAULT', 'en_US');
            if ('auto' === $lang) {
                $lang = 'en_US';
            }
            if (0 !== $runner->run($installDir, 'step5.php', [$steps[0]['from'], $targetVersion, $lang, 'upgrade', '', '', '', '1'])) {
                $this->reportScriptFailure('step5.php', $runner);

                return false;
            }

            $this->success('Database migration completed successfully!');
            $ok = true;
        } catch (Throwable $e) {
            $this->fail('Exception during migration: '.$e->getMessage().'. Database may be in an inconsistent state: restore from backup.');
        } finally {
            $this->installLock->close();
            $this->disableMaintenance();
        }

        return $ok;
    }

    private function reportScriptFailure(string $script, InstallScriptRunner $runner): void
    {
        $stderr = trim($runner->getStderr());
        if ('' !== $stderr) {
            $this->error("[{$script} stderr]\n".$stderr);
        }
        $this->fail("{$script} failed: the database may be in an inconsistent state, check it or restore the backup.");
    }

    private function enableMaintenance(string $note): bool
    {
        global $db, $conf;

        if ( ! empty($conf->global->MAIN_ONLY_LOGIN_ALLOWED)) {
            return true;
        }

        $login = DolibarrHelper::findFirstSuperAdmin();
        if (null === $login) {
            $this->fail('No super admin user found: cannot enable maintenance mode.');

            return false;
        }

        $this->info("Enabling maintenance mode (user: {$login})...");
        if (dolibarr_set_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $login, 'chaine', 0, $note, $conf->entity) <= 0) {
            $this->fail('Cannot enable maintenance mode: '.$db->lasterror());

            return false;
        }
        $conf->global->MAIN_ONLY_LOGIN_ALLOWED = $login;
        $this->maintenanceEnabledByUs = true;

        return true;
    }

    private function disableMaintenance(): void
    {
        global $db, $conf;

        if ( ! $this->maintenanceEnabledByUs) {
            return;
        }
        $this->maintenanceEnabledByUs = false;
        $this->info('Disabling maintenance mode...');
        if (dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity) < 0) {
            $this->fail('Cannot disable maintenance mode: run ./dolibarr system maintenance disable');
        }
        unset($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
    }

    // ==================== CLEANUP, OWNERSHIP ====================

    /**
     * Shutdown hook: runs even after exit() or a fatal error, so the install is never left
     * unlocked, in maintenance, or owned by root. Every step is idempotent.
     */
    public function runShutdownCleanup(): void
    {
        $this->installLock?->close();
        $this->disableMaintenance();
        $this->removeWorkDir();
        $this->doOwnershipRestore();
    }

    private function createWorkDir(): string
    {
        $dir = sys_get_temp_dir().'/dolicli-upgrade-'.bin2hex(random_bytes(8));
        if ( ! mkdir($dir, 0700)) {
            throw new \RuntimeException("Cannot create working directory {$dir}");
        }

        return $dir;
    }

    private function removeWorkDir(): void
    {
        if (null === $this->workDir) {
            return;
        }
        $dir = $this->workDir;
        $this->workDir = null;
        if (is_dir($dir)) {
            $this->runTool(['rm', '-rf', '--', $dir], "Cannot remove {$dir}", false);
        }
    }

    private function prepareOwnershipRestore(): void
    {
        $this->pendingOwnership = $this->captureOwnership(DOL_DOCUMENT_ROOT);
        $this->pendingDataOwnership = $this->captureOwnership(DOL_DATA_ROOT);

        if (null !== $this->pendingOwnership) {
            $this->info(sprintf('Detected install ownership: %s:%s (will be reapplied after copy)', $this->pendingOwnership['user'], $this->pendingOwnership['group']));
        }

        // Files of documents/ created from now on (install logs, install.lock, migrated files)
        // get the owner of documents/ back at the end. Existing files are not touched.
        $marker = tempnam(sys_get_temp_dir(), 'dolicli-start');
        if (false !== $marker) {
            touch($marker, time() - 1);
            $this->startMarker = $marker;
        }
    }

    private function doOwnershipRestore(): void
    {
        if ($this->ownershipRestorationDone || (null === $this->pendingOwnership && null === $this->pendingDataOwnership)) {
            return;
        }
        $this->ownershipRestorationDone = true;

        if (null !== $this->pendingOwnership) {
            $this->restoreOwnership(DOL_DOCUMENT_ROOT, $this->pendingOwnership, null);
        }
        if (null !== $this->pendingDataOwnership) {
            $this->restoreOwnership(DOL_DATA_ROOT, $this->pendingDataOwnership, $this->startMarker);
        }
        if (null !== $this->startMarker && is_file($this->startMarker)) {
            unlink($this->startMarker);
        }
    }

    /**
     * @return array{uid: int, gid: int, user: string, group: string}|null
     */
    private function captureOwnership(string $path): ?array
    {
        $reference = is_file($path.'/main.inc.php') ? $path.'/main.inc.php' : $path;
        if ( ! file_exists($reference)) {
            return null;
        }

        $uid = @fileowner($reference);
        $gid = @filegroup($reference);
        if (false === $uid || false === $gid) {
            return null;
        }

        $userInfo = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
        $groupInfo = function_exists('posix_getgrgid') ? @posix_getgrgid($gid) : false;

        return [
            'uid' => $uid,
            'gid' => $gid,
            'user' => is_array($userInfo) ? $userInfo['name'] : (string) $uid,
            'group' => is_array($groupInfo) ? $groupInfo['name'] : (string) $gid,
        ];
    }

    /**
     * Give files back to their owner when running as root. With $newerThan, only files
     * modified after that marker file are changed.
     *
     * @param  array{uid: int, gid: int, user: string, group: string}  $ownership
     */
    private function restoreOwnership(string $path, array $ownership, ?string $newerThan): void
    {
        $currentUid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if ($currentUid === $ownership['uid']) {
            return;
        }
        if (0 !== $currentUid) {
            $this->error(sprintf(
                'Cannot restore ownership of %s to uid=%d (running as uid=%d, not root): files may be owned by the wrong user.',
                $path,
                $ownership['uid'],
                $currentUid
            ));
            DolibarrHelper::log("upgrade: cannot restore ownership of {$path}", LOG_WARNING);

            return;
        }

        $this->info(sprintf('Restoring ownership to %s:%s on %s...', $ownership['user'], $ownership['group'], $path));

        // find -exec chown instead of chown -R: one protected file (chattr +i) does not stop the rest.
        $command = ['find', $path];
        if (null !== $newerThan && is_file($newerThan)) {
            $command[] = '-newer';
            $command[] = $newerThan;
        }
        $command = array_merge($command, ['-exec', 'chown', $ownership['uid'].':'.$ownership['gid'], '{}', '+']);

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if ( ! is_resource($process)) {
            $this->error('Cannot run find/chown to restore ownership.');

            return;
        }
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $failures = array_values(array_filter(explode("\n", $stderr), static fn (string $l): bool => str_contains($l, 'chown')));
        if ([] !== $failures) {
            $this->info(sprintf('Ownership restored with %d file(s) skipped (likely protected by chattr/immutable):', count($failures)));
            foreach (array_slice($failures, 0, 5) as $line) {
                $this->info("  {$line}");
            }

            return;
        }

        $this->success('Ownership restored.');
    }

    // ==================== HELPERS ====================

    /**
     * Resolve a --source path to the htdocs/ directory to copy into DOL_DOCUMENT_ROOT.
     */
    private function resolveLocalSource(string $path): ?string
    {
        $real = realpath($path);
        if (false === $real || ! is_dir($real)) {
            return null;
        }
        if (is_file($real.'/main.inc.php')) {
            return $real;
        }
        if (is_file($real.'/htdocs/main.inc.php')) {
            return $real.'/htdocs';
        }

        return null;
    }

    /**
     * Run an external tool (argument array, no shell); throws on failure unless $throw is false.
     *
     * @param  array<int, string>  $command
     */
    private function runTool(array $command, string $errorMessage, bool $throw = true): bool
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if ( ! is_resource($process)) {
            $message = $errorMessage.': cannot start '.$command[0];
        } else {
            stream_get_contents($pipes[1]);
            $stderr = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if (0 === $code) {
                return true;
            }
            $message = "{$errorMessage}: {$command[0]} exited with code {$code}".('' !== $stderr ? " ({$stderr})" : '');
        }

        DolibarrHelper::log('upgrade: '.$message, LOG_ERR);
        if ($throw) {
            throw new \RuntimeException($message);
        }
        $this->error($message);

        return false;
    }

    private function findBinary(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ('' !== $dir && is_executable($dir.'/'.$name)) {
                return $dir.'/'.$name;
            }
        }

        return null;
    }

    private function directorySize(string $path): int
    {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
