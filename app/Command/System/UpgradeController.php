<?php

declare(strict_types=1);

namespace App\Command\System;

use Minicli\Command\CommandController;
use App\Util\DolibarrHelper;

class UpgradeController extends CommandController
{
    private const DOLIBARR_DOWNLOAD_URL = 'https://www.dolibarr.org/files/stable/standard';
    private const DOLIBARR_VERSIONS_URL = 'https://www.dolibarr.org/files/stable/standard/';
    private const TEMP_DIR = '/tmp/dolibarr_upgrade';

    // Versions remembered between the file-copy phase and the DB migration phase.
    // Set by performFullUpgrade, consumed by performDatabaseMigration / executeUpgradeStep*.
    private ?string $migrationFromVersion = null;
    private ?string $migrationToVersion = null;

    // Ownership info captured at the start of performFullUpgrade, restored either at the
    // normal end of the method OR via a shutdown function (Dolibarr's install/step5.php
    // calls exit() which short-circuits everything past performDatabaseMigration()).
    private ?array $pendingOwnership = null;
    private ?array $pendingDataOwnership = null;
    private bool $ownershipRestorationDone = false;

    /**
     * Minicli's CommandCall stores key=value params with the literal key as the array key.
     * So `--version=18.0.10` is stored under the key `--version`, while `version=18.0.10` is
     * stored under `version`. dolicli historically uses the no-dash form (e.g. `name=...`,
     * `limit=...`), but the upgrade usage doc has always shown the GNU-style `--version=...`.
     * Be tolerant: try both.
     */
    private function getParamFlex(string $name): ?string
    {
        return $this->getParam($name) ?? $this->getParam('--' . $name);
    }

    private function hasParamFlex(string $name): bool
    {
        return $this->hasParam($name) || $this->hasParam('--' . $name);
    }

    public function usage(): void
    {
        $this->info("Dolibarr Upgrade Manager - Update files and migrate database

Usage:
  ./dolibarr system upgrade                        - Run database migration only
  ./dolibarr system upgrade --minor                - Upgrade to latest minor version
  ./dolibarr system upgrade --major                - Upgrade to latest major version
  ./dolibarr system upgrade --version=X.Y.Z        - Upgrade to specific version
  ./dolibarr system upgrade --check                - Check for available upgrades
  ./dolibarr system upgrade --list                 - List available versions

Options:
  --force          - Force database upgrade even if versions appear compatible
  --skip-backup    - Skip both file backup and database backup prompt
  --source=PATH    - Use local directory instead of downloading (skips download + extract).
                     PATH can point either to a Dolibarr htdocs/ directory or to a
                     directory containing htdocs/ (auto-detected).
  --yes            - Non-interactive mode: bypass all confirmation prompts (for batch use)
  --help           - Display this help message

Exit codes:
  0 - success
  1 - failure (for shell scripts looping over multiple installations)

Database migration only (when files already updated):
  ./dolibarr system upgrade
  ./dolibarr system upgrade --force

Full upgrade (download + files + database):
  ./dolibarr system upgrade --check
  ./dolibarr system upgrade --minor
  ./dolibarr system upgrade --major
  ./dolibarr system upgrade --version=22.0.0

Batch upgrade with local source (no download, no backup, no prompts):
  ./dolibarr system upgrade --version=18.0.10 --source=/tmp/dolibarr-18.0.10 --skip-backup --yes

WARNING:
- Always backup your database before upgrading!
- Full upgrade process will temporarily enable maintenance mode
- Test on staging environment first for major upgrades");
    }

    public function handle(): void
    {
        global $db, $conf;

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        // Handle check/list commands
        if ($this->hasFlag('check')) {
            $currentVersion = $this->getCurrentVersion();
            $this->info("Current Dolibarr version: $currentVersion\n");
            $this->checkForUpgrades($currentVersion);
            return;
        }

        if ($this->hasFlag('list')) {
            $this->listAvailableVersions();
            return;
        }

        // Determine if this is a full upgrade or database-only migration
        $targetVersion = null;

        if ($this->hasParamFlex('version')) {
            $targetVersion = $this->getParamFlex('version');
        } elseif ($this->hasFlag('minor')) {
            $currentVersion = $this->getCurrentVersion();
            $targetVersion = $this->getLatestMinorVersion($currentVersion);
        } elseif ($this->hasFlag('major')) {
            $targetVersion = $this->getLatestMajorVersion();
        }

        // source requires version to be explicit (we cannot infer it from a local directory)
        if ($this->hasParamFlex('source') && !$this->hasParamFlex('version')) {
            $this->error("--source requires --version=X.Y.Z to be specified explicitly.");
            exit(1);
        }

        if ($targetVersion) {
            // Full upgrade: download + files + database
            $success = $this->performFullUpgrade($targetVersion);
        } else {
            // Database migration only
            $success = $this->performDatabaseMigration();
        }

        if (!$success) {
            exit(1);
        }
    }

    // ==================== VERSION MANAGEMENT ====================

    private function getCurrentVersion(): string
    {
        if (defined('DOL_VERSION')) {
            return DOL_VERSION;
        }

        $versionFile = DOL_DOCUMENT_ROOT . '/core/lib/version.lib.php';
        if (file_exists($versionFile)) {
            include_once $versionFile;
            if (defined('DOL_VERSION')) {
                return DOL_VERSION;
            }
        }

        return 'unknown';
    }

    private function checkForUpgrades(string $currentVersion): void
    {
        $this->info("Checking for available upgrades...\n");

        $latestMinor = $this->getLatestMinorVersion($currentVersion);
        if ($latestMinor && version_compare($latestMinor, $currentVersion, '>')) {
            $this->success("Minor upgrade available: $latestMinor");
            $this->info("Run: ./dolibarr system upgrade --minor\n");
        } else {
            $this->info("No minor upgrades available for current major version.\n");
        }

        $latestMajor = $this->getLatestMajorVersion();
        if ($latestMajor && version_compare($latestMajor, $currentVersion, '>')) {
            $this->success("Major upgrade available: $latestMajor");
            $this->info("Run: ./dolibarr system upgrade --major\n");
        }
    }

    private function listAvailableVersions(): void
    {
        $this->info("Fetching available versions from dolibarr.org...\n");

        $versions = $this->fetchAvailableVersions();

        if (empty($versions)) {
            $this->error("Could not fetch versions from dolibarr.org");
            return;
        }

        $this->rawOutput("Available Dolibarr versions:\n");
        $this->rawOutput("─────────────────────────\n");

        $recentVersions = array_slice($versions, -20);
        foreach (array_reverse($recentVersions) as $version) {
            $this->rawOutput("  • " . $version . "\n");
        }
    }

    private function getLatestMinorVersion(string $currentVersion): ?string
    {
        preg_match('/^(\d+\.\d+)/', $currentVersion, $matches);
        if (!$matches) {
            return null;
        }

        $majorMinor = $matches[1];
        $versions = $this->fetchAvailableVersions();

        $minorVersions = array_filter($versions, function ($v) use ($majorMinor) {
            return strpos($v, $majorMinor) === 0;
        });

        if (empty($minorVersions)) {
            return null;
        }

        usort($minorVersions, 'version_compare');
        return end($minorVersions);
    }

    private function getLatestMajorVersion(): ?string
    {
        $versions = $this->fetchAvailableVersions();

        if (empty($versions)) {
            return null;
        }

        usort($versions, 'version_compare');
        return end($versions);
    }

    private function fetchAvailableVersions(): array
    {
        $html = @file_get_contents(self::DOLIBARR_VERSIONS_URL);
        if (!$html) {
            return [];
        }

        $versions = [];
        preg_match_all('/dolibarr-(\d+\.\d+\.\d+)\.(tgz|zip)/', $html, $matches);

        if (!empty($matches[1])) {
            $versions = array_unique($matches[1]);
            usort($versions, 'version_compare');
        }

        return $versions;
    }

    // ==================== FULL UPGRADE ====================

    private function performFullUpgrade(string $toVersion): bool
    {
        global $conf;

        $fromVersion = $this->getCurrentVersion();
        $useLocalSource = $this->hasParamFlex('source');
        $skipBackup = $this->hasFlag('skip-backup');
        $nonInteractive = $this->hasFlag('yes');

        $this->rawOutput("\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  DOLIBARR FULL UPGRADE\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");

        $this->info("Current version: $fromVersion");
        $this->info("Target version:  $toVersion");
        if ($useLocalSource) {
            $this->info("Source:          " . $this->getParamFlex('source') . " (local)");
        }
        $this->rawOutput("\n");

        if (version_compare($toVersion, $fromVersion, '<=')) {
            $this->info("Target version ($toVersion) is not newer than current version ($fromVersion)");
            $this->info("No upgrade needed.");
            return true;
        }

        // Resolve and validate local source up-front (fail fast before any destructive op)
        $resolvedSource = null;
        if ($useLocalSource) {
            $resolvedSource = $this->resolveLocalSource($this->getParamFlex('source'));
            if (!$resolvedSource) {
                $this->error("Invalid --source path: " . $this->getParamFlex('source'));
                $this->error("Expected either a Dolibarr htdocs/ directory or a directory containing htdocs/.");
                return false;
            }
            $this->info("Resolved source path: $resolvedSource");
        }

        if (!$nonInteractive && !$this->confirmUpgrade($fromVersion, $toVersion)) {
            $this->info("Upgrade cancelled by user.");
            return false;
        }

        if (!$skipBackup && !$nonInteractive) {
            $this->rawOutput("\n");
            $this->error("IMPORTANT: Have you backed up your database?");
            $this->info("Recommended: mysqldump -u user -p dbname > backup_$(date +%Y%m%d_%H%M%S).sql");
            $this->rawOutput("\nPress ENTER to continue or Ctrl+C to abort...");
            fgets(STDIN);
        }

        // Capture original uid/gid of the install before any file operation, so we can
        // restore them at the end. Needed when running as root: rsync would otherwise
        // leave the new files owned by root, breaking the web user's access. Same goes
        // for DOL_DATA_ROOT (documents/) since the DB migration writes log files there.
        $ownership = $this->captureOwnership(DOL_DOCUMENT_ROOT);
        $dataOwnership = defined('DOL_DATA_ROOT')
            ? $this->captureOwnership(DOL_DATA_ROOT)
            : null;
        if ($ownership) {
            $this->info(sprintf(
                "Detected install ownership: %s:%s (will be reapplied after copy)",
                $ownership['user'],
                $ownership['group']
            ));
        }
        if ($dataOwnership && (!$ownership || $dataOwnership['uid'] !== $ownership['uid'] || $dataOwnership['gid'] !== $ownership['gid'])) {
            $this->info(sprintf(
                "Detected data dir ownership: %s:%s",
                $dataOwnership['user'],
                $dataOwnership['group']
            ));
        }

        // Register a shutdown function so the chown is restored even if Dolibarr's
        // install scripts call exit() (step5.php does -- it terminates the whole PHP
        // process before we ever reach Step 6/6 here).
        $this->pendingOwnership = $ownership;
        $this->pendingDataOwnership = $dataOwnership;
        register_shutdown_function([$this, 'runShutdownOwnershipRestore']);

        $backupPath = null;
        $archivePath = null;
        $extractPath = null;

        try {
            $this->rawOutput("\n");
            $this->info("Starting full upgrade process...\n");

            // Step 1: Download (skipped if --source provided)
            if ($useLocalSource) {
                $this->rawOutput("Step 1/6: Skipping download (using local --source)\n");
            } else {
                $this->rawOutput("Step 1/6: Downloading Dolibarr $toVersion...\n");
                $archivePath = $this->downloadVersion($toVersion);
                if (!$archivePath) {
                    throw new \Exception("Download failed");
                }
                $this->success("Download completed\n");
            }

            // Step 2: Backup (skipped if --skip-backup)
            if ($skipBackup) {
                $this->rawOutput("Step 2/6: Skipping file backup (--skip-backup)\n");
            } else {
                $this->rawOutput("Step 2/6: Backing up current installation...\n");
                $backupPath = $this->backupCurrentInstallation($fromVersion);
                $this->success("Backup created: $backupPath\n");
            }

            // Step 3: Extract (skipped if --source provided)
            if ($useLocalSource) {
                $this->rawOutput("Step 3/6: Skipping extract (using local --source)\n");
                $sourcePath = $resolvedSource;
            } else {
                $this->rawOutput("Step 3/6: Extracting archive...\n");
                $extractPath = $this->extractArchive($archivePath, $toVersion);
                if (!$extractPath) {
                    throw new \Exception("Extraction failed");
                }
                $this->success("Archive extracted\n");
                $sourcePath = $extractPath . '/htdocs';
                if (!is_dir($sourcePath)) {
                    // Fallback for older archives where htdocs/ is the root
                    $sourcePath = $extractPath;
                }
            }

            // Step 4: Copy files
            $this->rawOutput("Step 4/6: Copying new files...\n");
            $this->copyNewFiles($sourcePath);
            $this->success("Files copied\n");

            // Step 5: Database migration
            $this->rawOutput("Step 5/6: Running database migration...\n");
            $this->migrationFromVersion = $fromVersion;
            $this->migrationToVersion = $toVersion;
            $migrationOk = $this->performDatabaseMigration();
            if (!$migrationOk) {
                throw new \Exception("Database migration failed");
            }
            $this->success("Database migrated\n");

            // Step 6: Cleanup (only what we created)
            $this->rawOutput("Step 6/6: Cleaning up temporary files...\n");
            if (!$useLocalSource) {
                $this->cleanup($archivePath, $extractPath);
            }
            $this->success("Cleanup completed\n");

            // Restore ownership here on the happy path. The shutdown function will
            // also try (idempotent via $ownershipRestorationDone flag) -- but most of
            // the time Dolibarr's exit() inside step5.php means we never reach this
            // line and only the shutdown hook actually runs.
            $this->doOwnershipRestore();

            $this->rawOutput("\n");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");
            $this->success("Upgrade completed successfully!");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");
            $this->info("Dolibarr has been upgraded from $fromVersion to $toVersion\n");
            return true;
        } catch (\Exception $e) {
            $this->error("\nUpgrade failed: " . $e->getMessage());
            $this->error("Your backup is available at: " . ($backupPath ?? 'not created'));
            $this->info("You may need to restore manually.");
            // Try to restore ownership even on failure, so the user can troubleshoot
            // without further root intervention.
            $this->doOwnershipRestore();
            return false;
        }
    }

    /**
     * Shutdown hook: PHP guarantees this runs even after exit() or fatal errors.
     * Idempotent: a normal end-of-method call to doOwnershipRestore() will mark the
     * job done, and this no-ops.
     */
    public function runShutdownOwnershipRestore(): void
    {
        $this->doOwnershipRestore();
    }

    private function doOwnershipRestore(): void
    {
        if ($this->ownershipRestorationDone) {
            return;
        }
        $this->ownershipRestorationDone = true;

        if ($this->pendingOwnership) {
            $this->restoreOwnership(DOL_DOCUMENT_ROOT, $this->pendingOwnership);
        }
        if ($this->pendingDataOwnership && defined('DOL_DATA_ROOT')) {
            $this->restoreOwnership(DOL_DATA_ROOT, $this->pendingDataOwnership);
        }
    }

    /**
     * Read uid/gid from a reference file inside the install. Returns null if we can't
     * determine ownership (path missing, stat fails). Prefer main.inc.php since it's
     * mandatory and owned by the same user as the rest of the install.
     *
     * @return array{uid:int,gid:int,user:string,group:string}|null
     */
    private function captureOwnership(string $path): ?array
    {
        $reference = $path . '/main.inc.php';
        if (!file_exists($reference)) {
            // Fallback to the directory itself
            $reference = $path;
            if (!file_exists($reference)) {
                return null;
            }
        }

        $uid = @fileowner($reference);
        $gid = @filegroup($reference);
        if ($uid === false || $gid === false) {
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
     * Recursive chown to restore uid/gid on the install. Silently no-op if we are not
     * running as root and the current uid already matches the target uid (nothing to do).
     *
     * @param array{uid:int,gid:int,user:string,group:string} $ownership
     */
    private function restoreOwnership(string $path, array $ownership): void
    {
        $currentUid = function_exists('posix_geteuid') ? posix_geteuid() : -1;

        // If we are already running as the target user, no chown needed.
        if ($currentUid === $ownership['uid']) {
            return;
        }

        // Only root can change ownership to an arbitrary uid. If we're not root and the
        // uids differ, chown will fail -- log it but don't crash.
        if ($currentUid !== 0 && $currentUid !== -1) {
            $this->error(sprintf(
                "Cannot restore ownership to uid=%d (running as uid=%d, not root). Files may be owned by the wrong user.",
                $ownership['uid'],
                $currentUid
            ));
            return;
        }

        $this->info(sprintf(
            "Restoring ownership to %s:%s on %s...",
            $ownership['user'],
            $ownership['group'],
            $path
        ));

        // Use `find ... -exec chown ...` instead of `chown -R` so a single protected
        // file (immutable attr, etc.) doesn't abort the whole recursion. We tolerate
        // partial failures and report them as warnings rather than blocking errors.
        $command = sprintf(
            'find %s -exec chown %d:%d {} + 2>&1',
            escapeshellarg($path),
            $ownership['uid'],
            $ownership['gid']
        );

        exec($command, $output, $returnCode);
        $failures = array_filter($output, fn($line) => stripos($line, 'chown:') !== false);

        if (!empty($failures)) {
            $this->info(sprintf(
                "Ownership restored with %d file(s) skipped (likely protected by chattr/immutable):",
                count($failures)
            ));
            foreach (array_slice($failures, 0, 5) as $line) {
                $this->info("  $line");
            }
            if (count($failures) > 5) {
                $this->info(sprintf("  ... and %d more", count($failures) - 5));
            }
            return;
        }

        $this->success("Ownership restored.");
    }

    /**
     * Resolve a --source path to the directory that should be rsync'd into DOL_DOCUMENT_ROOT.
     * Accepts either an htdocs/ directory directly, or a parent that contains htdocs/.
     *
     * @return string|null Absolute resolved path, or null if invalid.
     */
    private function resolveLocalSource(string $path): ?string
    {
        $real = realpath($path);
        if (!$real || !is_dir($real)) {
            return null;
        }

        // Case A: path itself contains main.inc.php -> it IS the htdocs/
        if (file_exists($real . '/main.inc.php')) {
            return $real;
        }

        // Case B: path contains htdocs/main.inc.php -> use the htdocs/ subdir
        if (file_exists($real . '/htdocs/main.inc.php')) {
            return $real . '/htdocs';
        }

        return null;
    }

    private function confirmUpgrade(string $from, string $to): bool
    {
        $this->rawOutput("\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  UPGRADE CONFIRMATION\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  From: $from\n");
        $this->rawOutput("  To:   $to\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");

        $this->info("Do you want to proceed with this upgrade? (yes/no)");
        $this->rawOutput("Answer: ");

        $answer = trim(fgets(STDIN));
        return in_array(strtolower($answer), ['yes', 'y', 'oui', 'o']);
    }

    private function downloadVersion(string $version): ?string
    {
        $extensions = ['tgz', 'zip'];

        foreach ($extensions as $ext) {
            $filename = "dolibarr-$version.$ext";
            $url = self::DOLIBARR_DOWNLOAD_URL . "/$filename";
            $archivePath = self::TEMP_DIR . "/$filename";

            if (!is_dir(self::TEMP_DIR)) {
                mkdir(self::TEMP_DIR, 0755, true);
            }

            $this->info("Downloading from: $url");

            $command = sprintf(
                'curl -L -o %s %s 2>&1',
                escapeshellarg($archivePath),
                escapeshellarg($url)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($archivePath) && filesize($archivePath) > 0) {
                $this->info("Downloaded: " . $this->formatFileSize(filesize($archivePath)));
                return $archivePath;
            }

            if (file_exists($archivePath)) {
                unlink($archivePath);
            }
        }

        return null;
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    private function backupCurrentInstallation(string $version): string
    {
        $backupDir = dirname(DOL_DOCUMENT_ROOT) . '/dolibarr_backup_' . $version . '_' . date('Ymd_His');

        $command = sprintf(
            'cp -r %s %s',
            escapeshellarg(DOL_DOCUMENT_ROOT),
            escapeshellarg($backupDir)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("Backup failed");
        }

        return $backupDir;
    }

    private function extractArchive(string $archivePath, string $version): ?string
    {
        $extractPath = self::TEMP_DIR . '/extracted';

        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }

        if (str_ends_with($archivePath, '.tgz') || str_ends_with($archivePath, '.tar.gz')) {
            $command = sprintf(
                'tar -xzf %s -C %s',
                escapeshellarg($archivePath),
                escapeshellarg($extractPath)
            );
        } elseif (str_ends_with($archivePath, '.zip')) {
            $command = sprintf(
                'unzip -q %s -d %s',
                escapeshellarg($archivePath),
                escapeshellarg($extractPath)
            );
        } else {
            return null;
        }

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            return null;
        }

        return $extractPath . '/dolibarr-' . $version;
    }

    private function copyNewFiles(string $sourcePath): void
    {
        $excludes = [
            'documents',
            'conf/conf.php',
            'custom'
        ];

        $excludeArgs = '';
        foreach ($excludes as $exclude) {
            $excludeArgs .= ' --exclude=' . escapeshellarg($exclude);
        }

        $command = sprintf(
            'rsync -av %s %s/ %s/',
            $excludeArgs,
            escapeshellarg($sourcePath),
            escapeshellarg(DOL_DOCUMENT_ROOT)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("File copy failed");
        }
    }

    private function cleanup(string $archivePath, string $extractPath): void
    {
        if (file_exists($archivePath)) {
            unlink($archivePath);
        }

        if (is_dir($extractPath)) {
            $command = sprintf('rm -rf %s', escapeshellarg($extractPath));
            exec($command);
        }
    }

    // ==================== DATABASE MIGRATION ====================

    private function performDatabaseMigration(): bool
    {
        global $db, $conf;

        $success = false;

        //erics
        if (!defined('NOSESSION')) {
            define('NOSESSION', '1');
        }
        chdir(DOL_DOCUMENT_ROOT . '/install/');

        $this->rawOutput("\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  DATABASE MIGRATION\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");

        if (!$this->validateUpgradeFiles()) {
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $wasInMaintenanceMode = !empty($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
        $maintenanceUser = null;

        if (!$wasInMaintenanceMode) {
            $maintenanceUser = DolibarrHelper::findFirstSuperAdmin();
            if (!$maintenanceUser) {
                $this->error("No super admin user found! Cannot enable maintenance mode.");
                $this->info("Please ensure at least one active super admin exists.");
                return false;
            }

            $this->info("Enabling maintenance mode (user: $maintenanceUser)...\n");
            dolibarr_set_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $maintenanceUser, 'chaine', 0, 'Temporary maintenance for upgrade', $conf->entity);
            $conf->global->MAIN_ONLY_LOGIN_ALLOWED = $maintenanceUser;
        }

        try {
            $this->rawOutput("Step 1/3: Checking version and preparing migration...\n");
            $this->rawOutput("────────────────────────────────────────────────────────\n");
            $this->lockUnlock(false);
            $result1 = $this->executeUpgradeStep1();

            if (!$result1) {
                $this->error("\nMigration step 1 failed. Aborting process.");
                $this->lockUnlock();
                return false;
            }

            $this->rawOutput("\n");

            $this->rawOutput("Step 2/3: Executing SQL migration scripts...\n");
            $this->rawOutput("────────────────────────────────────────────────────────\n");
            $result2 = $this->executeUpgradeStep2();

            if (!$result2) {
                $this->error("\nMigration step 2 failed. Database may be in inconsistent state!");
                $this->error("Please check database manually or restore from backup.");
                $this->lockUnlock();
                return false;
            }

            $this->rawOutput("\n");

            $this->rawOutput("Step 3/3: Finalizing migration...\n");
            $this->rawOutput("────────────────────────────────────────────────────────\n");
            $result3 = $this->executeUpgradeStep3();

            if (!$result3) {
                $this->error("\nMigration step 3 failed during finalization.");
                $this->lockUnlock();
                return false;
            }

            $this->rawOutput("\n");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");
            $this->success("Database migration completed successfully!");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");
            $this->lockUnlock();
            $success = true;
        } catch (\Exception $e) {
            $this->error("\nException during migration: " . $e->getMessage());
            $this->error("Database may be in inconsistent state. Please restore from backup.");
            $success = false;
        } finally {
            if (!$wasInMaintenanceMode) {
                $this->rawOutput("\n");
                $this->info("Disabling maintenance mode...");
                dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity);
                unset($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
            }
        }

        $this->rawOutput("\n");
        return $success;
    }

    private function validateUpgradeFiles(): bool
    {
        $upgradeFile = DOL_DOCUMENT_ROOT . '/install/upgrade.php';
        $upgrade2File = DOL_DOCUMENT_ROOT . '/install/upgrade2.php';
        $step5File = DOL_DOCUMENT_ROOT . '/install/step5.php';

        if (!file_exists($upgradeFile)) {
            $this->error("Upgrade file not found: $upgradeFile");
            return false;
        }

        if (!file_exists($upgrade2File)) {
            $this->error("Upgrade file not found: $upgrade2File");
            return false;
        }

        if (!file_exists($step5File)) {
            $this->error("Upgrade file not found: $step5File");
            return false;
        }

        return true;
    }

    /**
     * Run a Dolibarr install script (upgrade.php / upgrade2.php / step5.php) in a fresh
     * php subprocess. Doing it inline via `include` causes problems because:
     *  - Dolibarr's install/inc.php is designed for CLI mode with $argv set globally
     *  - The scripts call exit() on completion, which kills our main process and prevents
     *    cleanup (chown restore, lock recreation, maintenance mode disabling)
     *  - PHP's include_once tracks files globally, so calling 2 install scripts back to
     *    back from the same process means the 2nd one's `include_once 'inc.php'` is a
     *    no-op and $conffile / $dolibarr_main_* aren't initialized in scope
     *
     * Running in a subprocess avoids all of these: each script gets a fresh bootstrap,
     * its exit() is contained, and its argv is exactly what Dolibarr's CLI parser expects.
     */
    private function runDolibarrInstallScript(string $scriptName, array $args): bool
    {
        $installDir = DOL_DOCUMENT_ROOT . '/install';
        $scriptPath = $installDir . '/' . $scriptName;

        if (!file_exists($scriptPath)) {
            $this->error("Install script not found: $scriptPath");
            return false;
        }

        $cmd = array_merge([PHP_BINARY, $scriptPath], $args);

        $descriptors = [
            0 => ['pipe', 'r'], // stdin (unused)
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $installDir);
        if (!is_resource($process)) {
            $this->error("Failed to spawn subprocess for $scriptName");
            return false;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Stream stdout/stderr as it arrives so the user sees progress on long migrations.
        // Buffer per-line so strip_tags doesn't cut HTML tags mid-stream.
        $stdoutBuf = '';
        $stderrAll = '';

        $flushStdout = function (bool $final = false) use (&$stdoutBuf) {
            if ($stdoutBuf === '') {
                return;
            }
            $emit = $stdoutBuf;
            if (!$final) {
                // Keep the last possibly-incomplete line in the buffer
                $lastNl = strrpos($stdoutBuf, "\n");
                if ($lastNl === false) {
                    return; // No complete line yet; wait
                }
                $emit = substr($stdoutBuf, 0, $lastNl + 1);
                $stdoutBuf = substr($stdoutBuf, $lastNl + 1);
            } else {
                $stdoutBuf = '';
            }
            $clean = strip_tags($emit);
            $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5);
            $this->rawOutput($clean);
        };

        // Capture the exit code via proc_get_status as soon as we detect running=false.
        // proc_close can return -1 in cases where the child has already been reaped,
        // even when the process exited 0. proc_get_status gives the real exit code
        // exactly once per process; after that it returns -1.
        $exitCode = null;
        while (true) {
            $stdoutChunk = fread($pipes[1], 8192);
            $stderrChunk = fread($pipes[2], 8192);

            if ($stdoutChunk !== false && $stdoutChunk !== '') {
                $stdoutBuf .= $stdoutChunk;
                $flushStdout(false);
            }
            if ($stderrChunk !== false && $stderrChunk !== '') {
                $stderrAll .= $stderrChunk;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
                    $stdoutBuf .= $chunk;
                }
                while (($chunk = fread($pipes[2], 8192)) !== false && $chunk !== '') {
                    $stderrAll .= $chunk;
                }
                break;
            }

            if ($stdoutChunk === '' && $stderrChunk === '') {
                usleep(50000);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process); // ignore return value; we already have the real exit code

        $flushStdout(true);
        if ($stderrAll !== '') {
            $this->error("[$scriptName stderr]\n" . $stderrAll);
        }

        return $exitCode === 0;
    }

    private function executeUpgradeStep1(): bool
    {
        $from = $this->migrationFromVersion ?? '';
        $to = $this->migrationToVersion ?? '';
        $args = [$from, $to];
        if ($this->hasFlag('force')) {
            $args[] = 'ignoredbversion';
        }
        return $this->runDolibarrInstallScript('upgrade.php', $args);
    }

    private function executeUpgradeStep2(): bool
    {
        global $conf;
        $from = $this->migrationFromVersion ?? '';
        $to = $this->migrationToVersion ?? '';
        // upgrade2.php signature: previous_version new_version [module list]
        // We pass no module list (Dolibarr will process all enabled modules).
        return $this->runDolibarrInstallScript('upgrade2.php', [$from, $to]);
    }

    private function executeUpgradeStep3(): bool
    {
        global $conf;
        $from = $this->migrationFromVersion ?? '';
        $to = $this->migrationToVersion ?? '';
        $selectlang = $conf->global->MAIN_LANG_DEFAULT ?? 'en_US';
        // step5.php signature: previous_version new_version [selectlang] [action]
        //                       [login] [pass] [pass_verif] [installlock]
        // - action MUST be 'upgrade' (not 'set'): the 'set' branch is for fresh installs
        //   and does NOT update MAIN_VERSION_LAST_UPGRADE in llx_const, which leaves
        //   Dolibarr permanently telling the web user to visit /install/.
        // - installlock=1 makes step5 create DOL_DATA_ROOT/install.lock itself.
        return $this->runDolibarrInstallScript(
            'step5.php',
            [$from, $to, $selectlang, 'upgrade', '', '', '', '1']
        );
    }

    public function required(): array
    {
        return [];
    }

    /**
     * put or remove lockfile
     *
     * @param   [type]$lock  [$lock description]
     * @param   true         [ description]
     *
     * @return  [type]       [return description]
     */
    private function lockUnlock($lock = true)
    {
        $lockFile = DOL_DATA_ROOT . "/install.lock";
        if ($lock) {
            touch($lockFile);
        } else {
            @unlink($lockFile);
        }
    }
}
