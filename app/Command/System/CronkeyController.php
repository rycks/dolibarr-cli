<?php

declare(strict_types=1);

namespace App\Command\System;

use Minicli\Command\CommandController;

class CronkeyController extends CommandController
{
    public function usage(): void
    {
        $this->info("Dolibarr Scheduled Jobs Security Key (CRON_KEY) Management

Usage:
  ./dolibarr system cronkey show
  ./dolibarr system cronkey set key=XXXX
  ./dolibarr system cronkey generate
  ./dolibarr system cronkey clear

Commands:
  show     - Display the current CRON_KEY value
  set      - Set CRON_KEY to the provided value (overwrites existing)
  generate - Generate a random CRON_KEY (32 hex chars) and store it
  clear    - Delete the CRON_KEY constant

Parameters:
  key=XXXX - Value to use with 'set' (required)

Examples:
  ./dolibarr system cronkey show
  ./dolibarr system cronkey set key=mySecret123
  ./dolibarr system cronkey generate
  ./dolibarr system cronkey clear

Notes:
  This key is the value of 'securitykey' used in the URL
  public/cron/cron_run_jobs.php?securitykey=...
  It is stored in llx_const as the CRON_KEY constant.");
    }

    public function handle(): void
    {
        global $db;

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        if (!$this->validateDolibarrEnvironment()) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $args = $this->getArgs();
        $subcommand = $args[3] ?? null;

        match ($subcommand) {
            'show' => $this->handleShow(),
            'set' => $this->handleSet(),
            'generate' => $this->handleGenerate(),
            'clear' => $this->handleClear(),
            default => $this->usage(),
        };
    }

    private function validateDolibarrEnvironment(): bool
    {
        global $db;

        if (!isset($db) || !$db) {
            $this->error("Dolibarr database connection not available!");
            $this->info("Please ensure DOLPATH is set correctly and Dolibarr is accessible.");
            return false;
        }

        if (!defined('DOL_DOCUMENT_ROOT')) {
            $this->error("DOL_DOCUMENT_ROOT is not defined!");
            $this->info("Please ensure Dolibarr is properly initialized.");
            return false;
        }

        return true;
    }

    private function getCurrentKey(): ?string
    {
        global $db, $conf;

        $entity = (int) $conf->entity;
        $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const"
            . " WHERE name = " . "'" . $db->escape('CRON_KEY') . "'"
            . " AND entity = " . $entity;

        $resql = $db->query($sql);
        if (!$resql) {
            $this->error("Database query failed: " . $db->lasterror());
            return null;
        }

        if ($db->num_rows($resql) === 0) {
            $db->free($resql);
            return '';
        }

        $obj = $db->fetch_object($resql);
        $db->free($resql);

        $value = $obj->value ?? '';

        // CRON_KEY is stored as plain 'chaine' so no decoding is required,
        // but we keep dolDecrypt for safety on installs that may have encrypted it.
        if (function_exists('dolDecrypt') && $value !== '') {
            $decoded = dolDecrypt($value);
            if (is_string($decoded) && $decoded !== '') {
                $value = $decoded;
            }
        }

        return (string) $value;
    }

    private function handleShow(): void
    {
        $this->info("Reading CRON_KEY (scheduled jobs security key)...\n");

        $value = $this->getCurrentKey();

        if ($value === null) {
            return;
        }

        if ($value === '') {
            $this->display("CRON_KEY is not set (empty).");
            $this->info("No security key is required by Dolibarr to trigger cron jobs.");
            return;
        }

        $this->success("CRON_KEY = " . $value);
    }

    private function setKey(string $value, string $origin): void
    {
        global $db, $conf;

        $result = dolibarr_set_const(
            $db,
            'CRON_KEY',
            $value,
            'chaine',
            0,
            'Set by dolicli system cronkey ' . $origin,
            (int) $conf->entity
        );

        if ($result > 0) {
            $this->success("CRON_KEY updated successfully.");
            $this->display("New value: " . $value);
            $this->info("Used in URL: public/cron/cron_run_jobs.php?securitykey=" . urlencode($value));
        } else {
            $this->error("Failed to update CRON_KEY.");
            $this->info("Please check database permissions and try again.");
        }
    }

    private function handleSet(): void
    {
        $value = $this->getParam('key');

        if ($value === null || $value === '') {
            $this->error("Missing required parameter: key=XXXX");
            $this->info("Example: ./dolibarr system cronkey set key=mySecret123");
            return;
        }

        $this->info("Setting CRON_KEY to provided value...\n");
        $this->setKey($value, 'set');
    }

    private function handleGenerate(): void
    {
        try {
            $value = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $this->error("Unable to generate random key: " . $e->getMessage());
            return;
        }

        $this->info("Generating a random CRON_KEY...\n");
        $this->setKey($value, 'generate');
    }

    private function handleClear(): void
    {
        global $db, $conf;

        $this->info("Clearing CRON_KEY...\n");

        $result = dolibarr_del_const($db, 'CRON_KEY', (int) $conf->entity);

        if ($result > 0) {
            $this->success("CRON_KEY removed.");
            $this->display("Dolibarr no longer requires a security key for cron jobs.");
        } else {
            $this->info("CRON_KEY was not set or already removed.");
        }
    }

    public function required(): array
    {
        return [];
    }
}
