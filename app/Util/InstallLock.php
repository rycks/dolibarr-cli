<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Open the Dolibarr install/ pages for the duration of an upgrade or repair, then lock them again.
 *
 * When install/inc.php knows the upgrade.unlock file, that file is created next to
 * install.lock: only the upgrade pages open, the install wizard stays locked.
 * Older versions only know install.lock, which then has to be removed for a while.
 * close() always leaves an install.lock in place, and is safe to call twice
 * (normal end and shutdown function).
 */
final class InstallLock
{
    private ?string $mode = null;

    public function __construct(private readonly string $dataRoot, private readonly string $documentRoot)
    {
    }

    public static function forCurrentInstall(): self
    {
        return new self(DOL_DATA_ROOT, DOL_DOCUMENT_ROOT);
    }

    /**
     * @return string 'unlock' (upgrade.unlock created) or 'removed' (install.lock removed)
     */
    public function open(): string
    {
        $inc = @file_get_contents($this->documentRoot.'/install/inc.php');
        if (is_string($inc) && str_contains($inc, 'upgrade.unlock')) {
            if ( ! @touch($this->unlockFile())) {
                DolibarrHelper::log('install lock: cannot create '.$this->unlockFile(), LOG_ERR);
            }
            $this->mode = 'unlock';
        } else {
            foreach ([$this->dataRoot.'/install.lock', $this->documentRoot.'/install.lock'] as $lock) {
                if (is_file($lock) && ! @unlink($lock)) {
                    DolibarrHelper::log('install lock: cannot remove '.$lock, LOG_ERR);
                }
            }
            $this->mode = 'removed';
        }

        return $this->mode;
    }

    public function close(): void
    {
        if (null === $this->mode) {
            return;
        }

        if ('unlock' === $this->mode && is_file($this->unlockFile()) && ! @unlink($this->unlockFile())) {
            DolibarrHelper::log('install lock: cannot remove '.$this->unlockFile(), LOG_ERR);
        }
        $lock = $this->dataRoot.'/install.lock';
        if ( ! is_file($lock) && ! is_file($this->documentRoot.'/install.lock') && ! @touch($lock)) {
            DolibarrHelper::log('install lock: cannot create '.$lock.': the install wizard stays open', LOG_ERR);
        }
        $this->mode = null;
    }

    public function isLocked(): bool
    {
        return is_file($this->dataRoot.'/install.lock') || is_file($this->documentRoot.'/install.lock');
    }

    private function unlockFile(): string
    {
        return $this->dataRoot.'/upgrade.unlock';
    }
}
