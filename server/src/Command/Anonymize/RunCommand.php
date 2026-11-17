<?php

namespace Dolicli\Command\Anonymize;

use Dolicli\Anonymize\Anonymizer;
use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use Dolicli\Dolibarr\Maintenance;

final class RunCommand extends Command
{
    const LABELS = [
        'thirdparties' => 'Third parties',
        'contacts' => 'Contacts',
        'users' => 'Users',
        'employment' => 'Employment contracts',
        'members' => 'Members',
        'bank_rib' => 'Bank details (RIB)',
        'bank_accounts' => 'Bank accounts',
        'bank_lines' => 'Bank lines',
        'bank' => 'Bank data',
        'external_accounts' => 'External accounts',
        'communications' => 'Communications',
        'mailing_targets' => 'Mailing recipients',
        'mailings' => 'Mailings',
        'hr' => 'Candidates, attendees',
        'email_collectors' => 'Email collectors',
        'constants' => 'Setup constants',
        'notes' => 'Notes',
        'extrafields' => 'Text extrafields',
        'blocked_log' => 'Blocked log (purged)',
        'sessions' => 'Sessions (purged)',
    ];

    public function name(): string
    {
        return 'anonymize run';
    }

    public function summary(): string
    {
        return 'Anonymize all personal data (copies of databases only)';
    }

    public function category(): string
    {
        return self::DESTRUCTIVE;
    }

    public function params(): array
    {
        return [
            Param::flag('confirm', 'Execute the anonymization (otherwise: dry run)'),
            Param::flag('this-is-a-copy', 'Required with --confirm: this database is a copy, not production (the unalterable log is purged)'),
            Param::flag('skip-thirdparties', 'Skip third parties and contacts'),
            Param::flag('skip-users', 'Skip user accounts'),
            Param::flag('skip-members', 'Skip members'),
            Param::flag('skip-bank', 'Skip bank and payment data'),
            Param::value('keep-admin', 'LOGIN', 'Admin login to preserve (default: first super admin)'),
        ];
    }

    public function help(): string
    {
        return "Anonymized: third parties, contacts, members, users (names, logins, emails, phones, addresses,\n"
            . "birthdays, passwords, salaries, API keys), bank data, communications, mailings, HR, email collectors,\n"
            . "secret setup constants and company identity, notes, free text extrafields; blocked log and sessions\n"
            . "are purged. Not anonymized: amounts, references, products, the kept admin, files on disk.\n"
            . "Everything runs in one transaction: any error rolls every change back. A typed confirmation (YES)\n"
            . 'is asked on a terminal; --yes stands for it in scripts. MySQL/MariaDB, PostgreSQL, SQLite.';
    }

    public function execute(Context $ctx): array
    {
        global $conf, $mysoc;

        $keepAdmin = (string) $ctx->value('keep-admin');
        if ('' === $keepAdmin) {
            $keepAdmin = Dolibarr::firstSuperAdminLogin();
        }
        if ('' === $keepAdmin) {
            throw Failure::operation('NO_ADMIN_USER', 'No super admin found: use keep-admin=LOGIN to choose the admin account to preserve.');
        }
        Dolibarr::user($keepAdmin);
        $ctx->setActingUser($keepAdmin);

        $db = Dolibarr::db();
        $anonymizer = new Anonymizer($db);
        $skip = [
            'thirdparties' => $ctx->flag('skip-thirdparties'),
            'users' => $ctx->flag('skip-users'),
            'members' => $ctx->flag('skip-members'),
            'bank' => $ctx->flag('skip-bank'),
        ];
        $summary = $anonymizer->summary($keepAdmin, $skip);

        if (!$ctx->flag('confirm') || $ctx->dryRun()) {
            return ['executed' => false, 'kept_admin' => $keepAdmin, 'rows' => $summary];
        }
        if (!$ctx->flag('this-is-a-copy')) {
            throw Failure::usage(
                'NOT_A_COPY',
                'Refusing to anonymize without --this-is-a-copy: this purges the unalterable log (blockedlog) and cannot be undone. Run it on a copy of the database only.'
            );
        }

        $company = isset($mysoc) && !empty($mysoc->name) ? $mysoc->name : '(not set)';
        $ctx->progress("WARNING: this PERMANENTLY anonymizes all personal data of database {$conf->db->name} ({$company}). Kept admin: {$keepAdmin}.");
        if (!$ctx->confirmTyping('Anonymize this database?', 'YES')) {
            throw Failure::operation('CANCELLED', 'Aborted, nothing was changed.');
        }

        $maintenance = new Maintenance();
        if ('' === $maintenance->enable('Anonymization in progress', $keepAdmin)) {
            throw Failure::operation('MAINTENANCE_FAILED', $maintenance->lastError());
        }
        $ctx->defer(function () use ($maintenance, $ctx) {
            if ($maintenance->wasEnabledByUs() && !$maintenance->disable()) {
                $ctx->warn('MAINTENANCE_STILL_ON', 'Cannot disable the maintenance mode: run dolicli system maintenance disable.');
            }
        });

        $done = [];
        $db->begin();
        try {
            if (!$skip['thirdparties']) {
                $done['thirdparties'] = $anonymizer->anonymizeThirdparties();
                $done['contacts'] = $anonymizer->anonymizeContacts();
            }
            if (!$skip['users']) {
                $done['users'] = $anonymizer->anonymizeUsers($keepAdmin);
                $done['employment'] = $anonymizer->anonymizeEmployment();
            }
            if (!$skip['members']) {
                $done['members'] = $anonymizer->anonymizeMembers();
            }
            if (!$skip['bank']) {
                $done['bank'] = $anonymizer->anonymizeBankData();
                $done['external_accounts'] = $anonymizer->anonymizeExternalAccounts();
            }
            $done['communications'] = $anonymizer->anonymizeCommunications();
            $done['mailings'] = $anonymizer->anonymizeMailings();
            $done['hr'] = $anonymizer->anonymizeHr();
            $done['email_collectors'] = $anonymizer->anonymizeEmailCollectors();
            $done['constants'] = $anonymizer->anonymizeConstants();
            $done['notes'] = array_sum($anonymizer->clearNotes());
            $done['extrafields'] = array_sum($anonymizer->clearTextExtrafields());
            $done['blocked_log'] = $anonymizer->purgeTable('blockedlog');
            $done['sessions'] = $anonymizer->purgeTable('session');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw Failure::operation('ANONYMIZATION_FAILED', 'Anonymization failed, every change was rolled back: ' . $e->getMessage());
        }

        return ['executed' => true, 'kept_admin' => $keepAdmin, 'rows' => $done];
    }

    public function renderText(array $data): string
    {
        $out = $data['executed'] ? "Anonymization completed (files on disk were not modified).\n" : "DRY RUN - nothing is changed. Rows that would be anonymized:\n";
        foreach ($data['rows'] as $key => $count) {
            if ($count > 0 || $data['executed']) {
                $out .= '  ' . str_pad(array_key_exists($key, self::LABELS) ? self::LABELS[$key] : $key, 24) . $count . " rows\n";
            }
        }
        $out .= "Admin account preserved: {$data['kept_admin']}\n";

        return $data['executed'] ? $out : $out . "To execute, add --confirm --this-is-a-copy\n";
    }
}
