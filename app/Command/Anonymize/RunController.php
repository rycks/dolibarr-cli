<?php

declare(strict_types=1);

namespace App\Command\Anonymize;

use App\Support\Command;
use App\Util\Anonymizer;
use App\Util\DolibarrHelper;
use App\Util\Maintenance;
use Throwable;

class RunController extends Command
{
    public const OPTIONS_HELP = '  --confirm            Execute the anonymization (otherwise: dry run)
  --this-is-a-copy     Required with --confirm: state that this database is a copy, not production
                       (the unalterable log, blockedlog, is purged)
  --skip-thirdparties  Skip third parties and contacts
  --skip-users         Skip user accounts
  --skip-members       Skip members (adherents)
  --skip-bank          Skip bank and payment data
  keep-admin=LOGIN     Admin login to preserve (default: first super admin)';

    private ?Maintenance $maintenance = null;

    public function usage(): void
    {
        $this->info('Anonymize all personal data in the Dolibarr database

Usage:
  ./dolibarr anonymize run [options]

Options:
'.self::OPTIONS_HELP.'

What gets anonymized:
  - Third parties: names, addresses, phones, emails, social networks, SIREN/SIRET, VAT
  - Contacts, members, users: names, logins, emails, phones, addresses, birthdays,
    passwords (users and members cannot log in any more, except keep-admin)
  - Users: salary, API keys, national registration number
  - Bank data: IBAN/BIC of third parties, users and bank accounts, direct debit lines,
    bank line issuers and free labels, payment numbers
  - Communications: events, tickets, security events (emails, subjects, contents, IPs)
  - Mailings: recipients, unsubscriptions, bodies
  - HR: job candidates, event attendees
  - Email collectors: login/password credentials
  - Setup: secret constants (passwords, API keys, tokens) and the company identity
  - Notes: all note_private and note_public fields across all tables
  - Free text extrafields (varchar, text, html, mail, phone, url)
  - Blocked log and sessions: fully purged

What is NOT anonymized:
  - Financial amounts, document references, product catalog
  - The admin account given by keep-admin
  - Files on disk (documents, attachments)

Supported databases: MySQL/MariaDB, PostgreSQL, SQLite.');
    }

    public function handle(): void
    {
        global $db, $conf, $mysoc;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        $keepAdmin = $this->param('keep-admin') ?? DolibarrHelper::findFirstSuperAdmin();
        if (null === $keepAdmin || '' === $keepAdmin) {
            $this->fail('No super admin found: use keep-admin=LOGIN to choose the admin account to preserve.');

            return;
        }

        $anonymizer = new Anonymizer($db);
        $skip = [
            'thirdparties' => $this->hasFlag('skip-thirdparties'),
            'users' => $this->hasFlag('skip-users'),
            'members' => $this->hasFlag('skip-members'),
            'bank' => $this->hasFlag('skip-bank'),
        ];

        try {
            $summary = $anonymizer->summary($keepAdmin, $skip);
        } catch (Throwable $e) {
            $this->fail('Cannot read the database: '.$e->getMessage());

            return;
        }

        if ( ! $this->hasFlag('confirm')) {
            $this->info("DRY RUN - nothing is changed\n");
            $this->info("Admin account preserved: {$keepAdmin}\n");
            $this->rawOutput("  Rows that would be anonymized:\n\n");
            $this->printSummary($summary);
            $this->rawOutput("\n");
            $this->info('To execute, add --confirm --this-is-a-copy');

            return;
        }

        if ( ! $this->hasFlag('this-is-a-copy')) {
            $this->fail('Refusing to anonymize without --this-is-a-copy: this purges the unalterable log (blockedlog) and cannot be undone. Run it on a copy of the database only.');

            return;
        }

        $this->showWarning($summary, $keepAdmin, $conf, $mysoc);

        $answer = $this->readLine("\n  Type YES in capitals to confirm: ");
        if ('YES' !== trim((string) $answer)) {
            $this->info('Aborted.');

            return;
        }

        $this->rawOutput("\n");
        $this->execute($db, $anonymizer, $keepAdmin, $skip);
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function showWarning(array $summary, string $keepAdmin, object $conf, ?object $mysoc): void
    {
        $companyName = (isset($mysoc) && ! empty($mysoc->name)) ? $mysoc->name : '(not set)';
        $server = ($conf->db->host ?? '?').( ! empty($conf->db->port) ? ':'.$conf->db->port : '');

        $this->rawOutput("\n");
        $this->rawOutput("  +--------------------------------------------------------------+\n");
        $this->rawOutput("  |                     WARNING - DANGER                         |\n");
        $this->rawOutput("  |                                                              |\n");
        $this->rawOutput("  |  This PERMANENTLY anonymizes all personal data of this       |\n");
        $this->rawOutput("  |  Dolibarr database. It cannot be undone.                     |\n");
        $this->rawOutput("  +--------------------------------------------------------------+\n\n");

        $this->rawOutput("  Company   : {$companyName}\n");
        $this->rawOutput("  Server    : {$server}\n");
        $this->rawOutput('  Database  : '.($conf->db->name ?? '?')."\n");
        $this->rawOutput("  Kept admin: {$keepAdmin}\n\n");
        $this->rawOutput("  Rows that will be anonymized:\n\n");
        $this->printSummary($summary);
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function printSummary(array $summary): void
    {
        $labels = [
            'thirdparties' => 'Third parties',
            'contacts' => 'Contacts',
            'users' => 'Users',
            'employment' => 'Employment contracts',
            'members' => 'Members',
            'bank_rib' => 'Bank details (RIB)',
            'bank_accounts' => 'Bank accounts',
            'bank_lines' => 'Bank lines',
            'external_accounts' => 'External accounts',
            'communications' => 'Communications',
            'mailing_targets' => 'Mailing recipients',
            'hr' => 'Candidates, attendees',
            'email_collectors' => 'Email collectors',
            'blocked_log' => 'Blocked log',
            'sessions' => 'Sessions',
        ];

        foreach ($labels as $key => $label) {
            if (($summary[$key] ?? 0) > 0) {
                $this->rawOutput('    '.str_pad($label, 24).$summary[$key]." rows\n");
            }
        }
    }

    /**
     * @param  array<string, bool>  $skip
     */
    private function execute(object $db, Anonymizer $anonymizer, string $keepAdmin, array $skip): void
    {
        $this->maintenance = new Maintenance();
        $login = $this->maintenance->enable('Anonymization in progress', $keepAdmin);
        if (null === $login) {
            $this->fail($this->maintenance->lastError());

            return;
        }
        register_shutdown_function([$this, 'leaveMaintenance']);

        $db->begin();

        try {
            if ( ! $skip['thirdparties']) {
                $this->report('Third parties', $anonymizer->anonymizeThirdparties());
                $this->report('Contacts', $anonymizer->anonymizeContacts());
            }
            if ( ! $skip['users']) {
                $this->report('Users', $anonymizer->anonymizeUsers($keepAdmin));
                $this->report('Employment contracts', $anonymizer->anonymizeEmployment());
            }
            if ( ! $skip['members']) {
                $this->report('Members', $anonymizer->anonymizeMembers());
            }
            if ( ! $skip['bank']) {
                $this->report('Bank data', $anonymizer->anonymizeBankData());
                $this->report('External accounts', $anonymizer->anonymizeExternalAccounts());
            }
            $this->report('Communications', $anonymizer->anonymizeCommunications());
            $this->report('Mailings', $anonymizer->anonymizeMailings());
            $this->report('Candidates, attendees', $anonymizer->anonymizeHr());
            $this->report('Email collectors', $anonymizer->anonymizeEmailCollectors());
            $this->report('Setup constants', $anonymizer->anonymizeConstants());

            $this->rawOutput("\n");
            $this->report('Notes', array_sum($anonymizer->clearNotes()));
            $this->report('Text extrafields', array_sum($anonymizer->clearTextExtrafields()));
            $this->report('Blocked log (purged)', $anonymizer->purgeTable('blockedlog'));
            $this->report('Sessions (purged)', $anonymizer->purgeTable('session'));

            $db->commit();

            $this->rawOutput("\n");
            $this->success('Anonymization completed.');
            $this->info('Reminder: files on disk (documents, attachments) were not modified.');
        } catch (Throwable $e) {
            $db->rollback();
            $this->fail('Anonymization failed, every change was rolled back: '.$e->getMessage());
        } finally {
            $this->leaveMaintenance();
        }
    }

    public function leaveMaintenance(): void
    {
        if (null === $this->maintenance || ! $this->maintenance->wasEnabledByUs()) {
            return;
        }
        if ($this->maintenance->disable()) {
            $this->info('Maintenance mode disabled.');
        } else {
            $this->fail('Cannot disable maintenance mode: run ./dolibarr system maintenance disable');
        }
    }

    private function report(string $label, int $count): void
    {
        $this->success(str_pad($label, 24).': '.$count.' rows');
    }
}
