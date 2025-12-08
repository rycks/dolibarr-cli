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
  --skip-backup    - Skip database backup prompt (not recommended)
  --help           - Display this help message

Database migration only (when files already updated):
  ./dolibarr system upgrade
  ./dolibarr system upgrade --force

Full upgrade (download + files + database):
  ./dolibarr system upgrade --check
  ./dolibarr system upgrade --minor
  ./dolibarr system upgrade --major
  ./dolibarr system upgrade --version=22.0.0

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

        if ($this->hasParam('version')) {
            $targetVersion = $this->getParam('version');
        } elseif ($this->hasFlag('minor')) {
            $currentVersion = $this->getCurrentVersion();
            $targetVersion = $this->getLatestMinorVersion($currentVersion);
        } elseif ($this->hasFlag('major')) {
            $targetVersion = $this->getLatestMajorVersion();
        }

        if ($targetVersion) {
            // Full upgrade: download + files + database
            $this->performFullUpgrade($targetVersion);
        } else {
            // Database migration only
            $this->performDatabaseMigration();
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

        $minorVersions = array_filter($versions, function($v) use ($majorMinor) {
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

    private function performFullUpgrade(string $toVersion): void
    {
        global $conf;

        $fromVersion = $this->getCurrentVersion();

        $this->rawOutput("\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  DOLIBARR FULL UPGRADE\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");

        $this->info("Current version: $fromVersion");
        $this->info("Target version:  $toVersion\n");

        if (version_compare($toVersion, $fromVersion, '<=')) {
            $this->info("Target version ($toVersion) is not newer than current version ($fromVersion)");
            $this->info("No upgrade needed.");
            return;
        }

        if (!$this->confirmUpgrade($fromVersion, $toVersion)) {
            $this->info("Upgrade cancelled by user.");
            return;
        }

        if (!$this->hasFlag('skip-backup')) {
            $this->rawOutput("\n");
            $this->error("IMPORTANT: Have you backed up your database?");
            $this->info("Recommended: mysqldump -u user -p dbname > backup_$(date +%Y%m%d_%H%M%S).sql");
            $this->rawOutput("\nPress ENTER to continue or Ctrl+C to abort...");
            fgets(STDIN);
        }

        try {
            $this->rawOutput("\n");
            $this->info("Starting full upgrade process...\n");

            // Step 1: Download
            $this->rawOutput("Step 1/6: Downloading Dolibarr $toVersion...\n");
            $archivePath = $this->downloadVersion($toVersion);
            if (!$archivePath) {
                throw new \Exception("Download failed");
            }
            $this->success("Download completed\n");

            // Step 2: Backup
            $this->rawOutput("Step 2/6: Backing up current installation...\n");
            $backupPath = $this->backupCurrentInstallation($fromVersion);
            $this->success("Backup created: $backupPath\n");

            // Step 3: Extract
            $this->rawOutput("Step 3/6: Extracting archive...\n");
            $extractPath = $this->extractArchive($archivePath, $toVersion);
            if (!$extractPath) {
                throw new \Exception("Extraction failed");
            }
            $this->success("Archive extracted\n");

            // Step 4: Copy files
            $this->rawOutput("Step 4/6: Copying new files...\n");
            $this->copyNewFiles($extractPath);
            $this->success("Files copied\n");

            // Step 5: Database migration
            $this->rawOutput("Step 5/6: Running database migration...\n");
            $this->performDatabaseMigration();
            $this->success("Database migrated\n");

            // Step 6: Cleanup
            $this->rawOutput("Step 6/6: Cleaning up temporary files...\n");
            $this->cleanup($archivePath, $extractPath);
            $this->success("Cleanup completed\n");

            $this->rawOutput("\n");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");
            $this->success("Upgrade completed successfully!");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");
            $this->info("Dolibarr has been upgraded from $fromVersion to $toVersion\n");

        } catch (\Exception $e) {
            $this->error("\nUpgrade failed: " . $e->getMessage());
            $this->error("Your backup is available at: " . ($backupPath ?? 'not created'));
            $this->info("You may need to restore manually.");
        }
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

    private function performDatabaseMigration(): void
    {
        global $db, $conf;

        $this->rawOutput("\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n");
        $this->rawOutput("  DATABASE MIGRATION\n");
        $this->rawOutput("═══════════════════════════════════════════════════════════\n\n");

        if (!$this->validateUpgradeFiles()) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $wasInMaintenanceMode = !empty($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
        $maintenanceUser = null;

        if (!$wasInMaintenanceMode) {
            $maintenanceUser = DolibarrHelper::findFirstSuperAdmin();
            if (!$maintenanceUser) {
                $this->error("No super admin user found! Cannot enable maintenance mode.");
                $this->info("Please ensure at least one active super admin exists.");
                return;
            }

            $this->info("Enabling maintenance mode (user: $maintenanceUser)...\n");
            dolibarr_set_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $maintenanceUser, 'chaine', 0, 'Temporary maintenance for upgrade', $conf->entity);
            $conf->global->MAIN_ONLY_LOGIN_ALLOWED = $maintenanceUser;
        }

        try {
            $this->rawOutput("Step 1/3: Checking version and preparing migration...\n");
            $this->rawOutput("────────────────────────────────────────────────────────\n");
            $result1 = $this->executeUpgradeStep1();

            if (!$result1) {
                $this->error("\nMigration step 1 failed. Aborting process.");
                return;
            }

            $this->rawOutput("\n");

            $this->rawOutput("Step 2/3: Executing SQL migration scripts...\n");
            $this->rawOutput("────────────────────────────────────────────────────────\n");
            $result2 = $this->executeUpgradeStep2();

            if (!$result2) {
                $this->error("\nMigration step 2 failed. Database may be in inconsistent state!");
                $this->error("Please check database manually or restore from backup.");
                return;
            }

            $this->rawOutput("\n");

            $this->rawOutput("Step 3/3: Finalizing migration...\n");
            $this->rawOutput("────────────────────────────────────────────────────────\n");
            $result3 = $this->executeUpgradeStep3();

            if (!$result3) {
                $this->error("\nMigration step 3 failed during finalization.");
                return;
            }

            $this->rawOutput("\n");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");
            $this->success("Database migration completed successfully!");
            $this->rawOutput("═══════════════════════════════════════════════════════════\n");

        } catch (\Exception $e) {
            $this->error("\nException during migration: " . $e->getMessage());
            $this->error("Database may be in inconsistent state. Please restore from backup.");
        } finally {
            if (!$wasInMaintenanceMode) {
                $this->rawOutput("\n");
                $this->info("Disabling maintenance mode...");
                dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity);
                unset($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
            }
        }

        $this->rawOutput("\n");
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

    private function executeUpgradeStep1(): bool
    {
        global $db, $conf;

        try {
            $_GET['action'] = 'upgrade';
            if ($this->hasFlag('force')) {
                $_GET['force'] = '1';
            }

            ob_start();
            include DOL_DOCUMENT_ROOT . '/install/upgrade.php';
            $output = ob_get_clean();

            $cleanOutput = strip_tags($output);
            $cleanOutput = html_entity_decode($cleanOutput, ENT_QUOTES | ENT_HTML5);
            $this->rawOutput($cleanOutput);

            unset($_GET['action']);
            unset($_GET['force']);

            return true;

        } catch (\Exception $e) {
            ob_end_clean();
            $this->error("Error in step 1: " . $e->getMessage());
            return false;
        }
    }

    private function executeUpgradeStep2(): bool
    {
        global $db, $conf;

        try {
            $_GET['action'] = 'upgrade';
            $_GET['selectlang'] = $conf->global->MAIN_LANG_DEFAULT ?? 'en_US';

            ob_start();
            include DOL_DOCUMENT_ROOT . '/install/upgrade2.php';
            $output = ob_get_clean();

            $cleanOutput = strip_tags($output);
            $cleanOutput = html_entity_decode($cleanOutput, ENT_QUOTES | ENT_HTML5);
            $this->rawOutput($cleanOutput);

            unset($_GET['action']);
            unset($_GET['selectlang']);

            return true;

        } catch (\Exception $e) {
            ob_end_clean();
            $this->error("Error in step 2: " . $e->getMessage());
            return false;
        }
    }

    private function executeUpgradeStep3(): bool
    {
        global $db, $conf;

        try {
            $_GET['action'] = 'set';

            ob_start();
            include DOL_DOCUMENT_ROOT . '/install/step5.php';
            $output = ob_get_clean();

            $cleanOutput = strip_tags($output);
            $cleanOutput = html_entity_decode($cleanOutput, ENT_QUOTES | ENT_HTML5);
            $this->rawOutput($cleanOutput);

            unset($_GET['action']);

            return true;

        } catch (\Exception $e) {
            ob_end_clean();
            $this->error("Error in step 3: " . $e->getMessage());
            return false;
        }
    }

    public function required(): array
    {
        return [];
    }
}
