<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Support\Command;

class CronkeyController extends Command
{
    public function summary(): string
    {
        return 'Show or change the scheduled jobs security key';
    }

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
  clear    - Delete the CRON_KEY constant: scheduled jobs can then no longer be
             triggered by URL (Dolibarr refuses calls without a key)

Parameters:
  key=XXXX - Value to use with 'set' (required)

Notes:
  This key is the 'securitykey' parameter of the URL that triggers scheduled jobs
  over HTTP (".$this->cronUrl().'?securitykey=...).
  It is stored in llx_const as the CRON_KEY constant of the current entity.
  The command line script scripts/cron/cron_run_jobs.php does not need it.');
    }

    public function handle(): void
    {
        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

        match ($this->action()) {
            'show' => $this->handleShow(),
            'set' => $this->handleSet(),
            'generate' => $this->handleGenerate(),
            'clear' => $this->handleClear(),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): void
    {
        $this->usage();
        $this->fail('Give an action: show, set, generate or clear.');
    }

    /**
     * URL of the HTTP cron trigger: cron_run_jobs_by_url.php in recent versions.
     */
    private function cronUrl(): string
    {
        $byUrl = 'public/cron/cron_run_jobs_by_url.php';
        if (defined('DOL_DOCUMENT_ROOT') && ! is_file(DOL_DOCUMENT_ROOT.'/'.$byUrl) && is_file(DOL_DOCUMENT_ROOT.'/public/cron/cron_run_jobs.php')) {
            return 'public/cron/cron_run_jobs.php';
        }

        return $byUrl;
    }

    private function getCurrentKey(): ?string
    {
        global $db, $conf;

        $sql = 'SELECT value FROM '.MAIN_DB_PREFIX."const WHERE name = 'CRON_KEY' AND entity = ".((int) $conf->entity);
        $resql = $db->query($sql);
        if ( ! $resql) {
            $this->fail('Database query failed: '.$db->lasterror());

            return null;
        }

        $obj = $db->fetch_object($resql);
        $value = $obj ? (string) $obj->value : '';

        // Recent versions store this constant encrypted (dolibarr_set_const encrypts *_KEY names).
        if ('' !== $value && function_exists('dolDecrypt')) {
            $decoded = dolDecrypt($value);
            if (is_string($decoded) && '' !== $decoded) {
                $value = $decoded;
            }
        }

        return $value;
    }

    private function handleShow(): void
    {
        $value = $this->getCurrentKey();
        if (null === $value) {
            return;
        }

        if ('' === $value) {
            $this->display('CRON_KEY is not set: scheduled jobs cannot be triggered by URL.');

            return;
        }

        $this->rawOutput($value."\n");
    }

    private function setKey(string $value, string $origin): void
    {
        global $db, $conf;

        $result = dolibarr_set_const($db, 'CRON_KEY', $value, 'chaine', 0, 'Set by dolicli system cronkey '.$origin, (int) $conf->entity);
        if ($result <= 0) {
            $this->fail('Failed to update CRON_KEY: '.$db->lasterror());

            return;
        }

        $this->success('CRON_KEY updated. New value:');
        $this->rawOutput($value."\n");
        $this->info('URL: '.$this->cronUrl().'?securitykey='.urlencode($value));
        $this->info('Update the scheduler (crontab...) that calls this URL.');
    }

    private function handleSet(): void
    {
        $value = $this->param('key');
        if (null === $value || '' === $value) {
            $this->fail('Missing required parameter: key=XXXX');

            return;
        }

        $this->setKey($value, 'set');
    }

    private function handleGenerate(): void
    {
        $this->setKey(bin2hex(random_bytes(16)), 'generate');
    }

    private function handleClear(): void
    {
        global $db, $conf;

        $result = dolibarr_del_const($db, 'CRON_KEY', (int) $conf->entity);
        if ($result < 0) {
            $this->fail('Failed to remove CRON_KEY: '.$db->lasterror());

            return;
        }

        $this->success('CRON_KEY removed.');
        $this->info('Scheduled jobs can no longer be triggered by URL until a new key is set.');
    }
}
