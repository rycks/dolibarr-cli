<?php

namespace Dolicli\Upgrade;

use Dolicli\Cli\Failure;
use Dolicli\Instance\Instance;
use Dolicli\System\Files;
use Dolicli\System\Log;
use Dolicli\System\Posix;
use Dolicli\System\Process;

/**
 * New htdocs prepared next to the current one, then switched by rename().
 *
 * Copying over the old htdocs would leave the files removed by the new version.
 * The new tree gets conf/conf.php, custom/ and the local top-level files of the
 * current one; the switch renames htdocs to htdocs.old-<date> and htdocs.new-<version>
 * to htdocs, so going back is immediate (restore()).
 */
final class Deployer
{
    /** @var string */
    private $htdocs;

    /** @var string */
    private $dataRoot;

    public function __construct(string $htdocs, string $dataRoot)
    {
        $this->htdocs = rtrim($htdocs, '/');
        $this->dataRoot = rtrim($dataRoot, '/');
    }

    public static function current(): self
    {
        return new self(DOL_DOCUMENT_ROOT, DOL_DATA_ROOT);
    }

    public function parent(): string
    {
        return dirname($this->htdocs);
    }

    public function stagedDir(string $version): string
    {
        return $this->htdocs . '.new-' . $version;
    }

    /**
     * Reasons why the new tree cannot be prepared and switched by dolicli (empty: possible).
     *
     * @return array<int, string>
     */
    public function switchBlockers(): array
    {
        $blockers = [];
        $data = realpath($this->dataRoot);
        $htdocs = realpath($this->htdocs);
        if (false !== $data && false !== $htdocs && 0 === strpos($data . '/', $htdocs . '/')) {
            $blockers[] = "documents/ ({$data}) is inside htdocs: move it out of htdocs before an upgrade by switch";
        }
        if (!is_writable($this->parent())) {
            $blockers[] = $this->parent() . ' is not writable by ' . Posix::currentUserName() . ': the switch of directories is a manual action';
        }

        return $blockers;
    }

    /**
     * Build htdocs.new-<version> from an archive (tgz, zip) or a directory.
     *
     * @return array<string, mixed>
     */
    public function stage(string $source, string $version, string $signature, callable $progress): array
    {
        $blockers = $this->switchBlockers();
        if ([] !== $blockers) {
            throw Failure::operation('MANUAL_ACTION_REQUIRED', implode('; ', $blockers) . '.');
        }
        if (!MigrationPlanner::isVersion($version)) {
            throw Failure::usage('INVALID_VALUE', "Invalid version '{$version}': expected X.Y.Z.");
        }
        $target = $this->stagedDir($version);
        if (file_exists($target)) {
            $progress("Removing a previous {$target}");
            Files::removeTree($target);
        }

        $work = Files::tempDir($this->parent());
        try {
            if (is_dir($source)) {
                $tree = self::findHtdocs($source, $version);
                $progress("Copying {$tree}");
                $this->run(['cp', '-a', $tree . '/.', $work . '/htdocs'], 'Copy of the new files failed', true);
                $tree = $work . '/htdocs';
            } else {
                $progress('Extracting ' . basename($source));
                $this->extract($source, $work);
                $tree = self::findHtdocs($work, $version);
            }
            $version = MigrationPlanner::clean($version);
            $found = (new Instance('staged', $tree, 'staged'))->filesVersion();
            if ('' !== $found && MigrationPlanner::clean($found) !== $version) {
                throw Failure::operation('VERSION_MISMATCH', "The new files are Dolibarr {$found}, not {$version}.");
            }

            $verified = false;
            if ('' !== $signature) {
                $progress('Checking the new files against the official signature');
                $this->verify($tree, $signature);
                $verified = true;
            }

            if (!@rename($tree, $target)) {
                throw Failure::operation('DEPLOY_FAILED', "Cannot move the new files to {$target}.");
            }
        } finally {
            Files::removeTree($work);
        }

        $carried = $this->carryLocalFiles($target, $progress);
        $owner = $this->alignOwner($target);

        return ['staged' => $target, 'version' => $version, 'verified' => $verified, 'carried' => $carried, 'owner' => $owner];
    }

    /**
     * htdocs -> htdocs.old-<date>, htdocs.new-<version> -> htdocs.
     *
     * @return array<string, string>
     */
    public function switchTo(string $version): array
    {
        $staged = $this->stagedDir($version);
        if (!is_file($staged . '/main.inc.php')) {
            throw Failure::operation('NOTHING_STAGED', "No prepared files in {$staged}: run dolicli upgrade deploy first.");
        }
        $blockers = $this->switchBlockers();
        if ([] !== $blockers) {
            throw Failure::operation('MANUAL_ACTION_REQUIRED', implode('; ', $blockers) . '.');
        }
        $old = $this->htdocs . '.old-' . date('YmdHis');
        if (!@rename($this->htdocs, $old)) {
            throw Failure::operation('SWITCH_FAILED', "Cannot rename {$this->htdocs} to {$old}.");
        }
        if (!@rename($staged, $this->htdocs)) {
            if (!@rename($old, $this->htdocs)) {
                Log::error("switch: cannot put {$old} back to {$this->htdocs}");
            }
            throw Failure::operation('SWITCH_FAILED', "Cannot rename {$staged} to {$this->htdocs}: previous files put back.");
        }
        // The current directory of this process was the old tree.
        @chdir($this->htdocs);

        return ['htdocs' => $this->htdocs, 'previous' => $old];
    }

    /**
     * Put back the most recent htdocs.old-<date>; the current tree becomes htdocs.failed-<date>.
     *
     * @return array<string, string>
     */
    public function restore(): array
    {
        $previous = $this->previousTrees();
        if ([] === $previous) {
            throw Failure::operation('NOTHING_TO_RESTORE', "No {$this->htdocs}.old-* directory to restore.");
        }
        $old = end($previous);
        $failed = $this->htdocs . '.failed-' . date('YmdHis');
        if (!@rename($this->htdocs, $failed)) {
            throw Failure::operation('RESTORE_FAILED', "Cannot rename {$this->htdocs} to {$failed}.");
        }
        if (!@rename($old, $this->htdocs)) {
            @rename($failed, $this->htdocs);
            throw Failure::operation('RESTORE_FAILED', "Cannot rename {$old} to {$this->htdocs}: current files put back.");
        }
        @chdir($this->htdocs);

        return ['htdocs' => $this->htdocs, 'restored_from' => $old, 'set_aside' => $failed];
    }

    /**
     * htdocs.old-<date> directories, oldest first.
     *
     * @return array<int, string>
     */
    public function previousTrees(): array
    {
        $found = [];
        $entries = @scandir($this->parent());
        if (is_array($entries)) {
            $base = basename($this->htdocs);
            foreach ($entries as $entry) {
                if (1 === preg_match('/^' . preg_quote($base, '/') . '\.old-\d{14}$/', $entry) && is_dir($this->parent() . '/' . $entry)) {
                    $found[] = $this->parent() . '/' . $entry;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return array<int, string>
     */
    public function stagedTrees(): array
    {
        $found = [];
        $entries = @scandir($this->parent());
        if (is_array($entries)) {
            $base = basename($this->htdocs);
            foreach ($entries as $entry) {
                if (0 === strpos($entry, $base . '.new-') && is_dir($this->parent() . '/' . $entry)) {
                    $found[] = $this->parent() . '/' . $entry;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Compare a tree with a signature (filelist-x.y.z.xml): any missing or modified file is a failure.
     */
    public function verify(string $tree, string $signatureFile)
    {
        $content = @file_get_contents($signatureFile);
        $root = is_string($content) ? FileSignature::parse($content) : null;
        if (null === $root) {
            throw Failure::operation('SIGNATURE_INVALID', "Invalid or unreadable signature {$signatureFile}.");
        }
        $result = FileSignature::compare($root, $tree);
        $problems = [];
        foreach ($result['missing'] as $file) {
            $problems[] = 'missing ' . $file['filename'];
        }
        foreach ($result['updated'] as $file) {
            $problems[] = 'modified ' . $file['filename'];
        }
        if ([] !== $problems) {
            Log::error('signature check: ' . implode(', ', array_slice($problems, 0, 20)));
            throw Failure::operation(
                'SIGNATURE_MISMATCH',
                count($problems) . ' file(s) differ from the official signature: ' . implode(', ', array_slice($problems, 0, 5)) . (count($problems) > 5 ? '...' : '') . ' Nothing was changed.',
                ['files' => array_slice($problems, 0, 200)]
            );
        }
    }

    private function extract(string $archive, string $work)
    {
        if (!is_file($archive)) {
            throw Failure::operation('ARCHIVE_NOT_FOUND', "Archive not found: {$archive}");
        }
        if (1 === preg_match('/\.(tgz|tar\.gz)$/', $archive)) {
            $this->run(['tar', '-xzf', $archive, '-C', $work, '--no-same-owner'], 'Extraction failed', true);
        } elseif (1 === preg_match('/\.zip$/', $archive)) {
            $this->run(['unzip', '-q', $archive, '-d', $work], 'Extraction failed', true);
        } else {
            throw Failure::usage('INVALID_VALUE', "Unknown archive type: {$archive} (expected .tgz, .tar.gz or .zip).");
        }
    }

    private static function findHtdocs(string $dir, string $version): string
    {
        $dir = rtrim($dir, '/');
        foreach ([$dir, $dir . '/htdocs', $dir . "/dolibarr-{$version}/htdocs", $dir . "/dolibarr-{$version}"] as $candidate) {
            if (is_file($candidate . '/main.inc.php') && is_file($candidate . '/filefunc.inc.php')) {
                return $candidate;
            }
        }
        throw Failure::operation('NO_HTDOCS', "No Dolibarr htdocs found in {$dir}.");
    }

    /**
     * conf/conf.php, custom/ and the top-level local files of the current tree.
     *
     * @return array<int, string>
     */
    private function carryLocalFiles(string $target, callable $progress): array
    {
        $carried = [];
        if (is_file($this->htdocs . '/conf/conf.php')) {
            Files::ensureDir($target . '/conf', 0755);
            $this->run(['cp', '-p', $this->htdocs . '/conf/conf.php', $target . '/conf/conf.php'], 'Copy of conf.php failed', true);
            $carried[] = 'conf/conf.php';
        }
        if (is_dir($this->htdocs . '/custom')) {
            $progress('Copying custom/');
            Files::removeTree($target . '/custom');
            $this->run(['cp', '-a', $this->htdocs . '/custom', $target . '/custom'], 'Copy of custom/ failed', true);
            $carried[] = 'custom/';
        }
        $entries = @scandir($this->htdocs);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry || file_exists($target . '/' . $entry)) {
                    continue;
                }
                $this->run(['cp', '-a', $this->htdocs . '/' . $entry, $target . '/' . $entry], "Copy of {$entry} failed", true);
                $carried[] = $entry;
            }
        }

        return $carried;
    }

    /**
     * Give the new tree the owner of the current one: possible as root only, reported otherwise.
     *
     * @return array<string, mixed>
     */
    private function alignOwner(string $target): array
    {
        $uid = @fileowner($this->htdocs . '/main.inc.php');
        $gid = @filegroup($this->htdocs . '/main.inc.php');
        $newUid = @fileowner($target . '/main.inc.php');
        if (false === $uid || false === $gid || $uid === $newUid) {
            return ['owner' => Posix::userName((int) $newUid), 'kept' => true];
        }
        if (Posix::isRoot()) {
            $this->run(['chown', '-R', $uid . ':' . $gid, $target], 'chown of the new files failed', true);

            return ['owner' => Posix::userName($uid), 'kept' => true];
        }
        $message = "The new files belong to " . Posix::userName((int) $newUid) . ', the current ones to ' . Posix::userName($uid)
            . ": run chown -R {$uid}:{$gid} {$target} as root before the switch.";
        Log::warning($message);

        return ['owner' => Posix::userName((int) $newUid), 'kept' => false, 'message' => $message];
    }

    /**
     * @param array<int, string> $command
     */
    private function run(array $command, string $error, bool $throw): bool
    {
        $process = new Process();
        if (0 === $process->run($command)) {
            return true;
        }
        $message = $error . ': ' . $command[0] . ' exited with code ' . $process->exitCode() . ('' !== $process->error() ? ' (' . $process->error() . ')' : '');
        Log::error($message);
        if ($throw) {
            throw Failure::operation('DEPLOY_FAILED', $message);
        }

        return false;
    }
}
