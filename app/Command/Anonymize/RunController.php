<?php

declare(strict_types=1);

namespace App\Command\Anonymize;

use Minicli\Command\CommandController;
use App\Util\DolibarrHelper;
use App\Util\AnonymizeHelper;
use Exception;

class RunController extends CommandController
{
    public function usage(): void
    {
        $this->info("Anonymize all personal data in the Dolibarr database

Usage:
  ./dolibarr anonymize run [options]

Options:
  --confirm            Execute the anonymization (required)
  --skip-thirdparties  Skip third parties and contacts
  --skip-users         Skip user accounts
  --skip-members       Skip members (adherents)
  --skip-bank          Skip bank/payment data
  keep-admin=LOGIN     Admin login to preserve (default: first super admin)

What gets anonymized:
  - Third parties: names, addresses, phones, emails, SIREN/SIRET, TVA
  - Contacts: names, addresses, phones, emails, birthdays
  - Users: names, logins, emails, phones, addresses, salary, API keys
  - Members: names, emails, phones, addresses, login/password
  - Bank data: IBAN, BIC, account numbers, card details
  - Communications: email addresses, subjects, content, IPs
  - Mailings: recipient names, emails
  - Employment: salary data
  - Email collectors: login/password credentials
  - Notes: all note_private and note_public fields across all tables
  - Blocked log: fully purged
  - Sessions: fully purged

What is NOT anonymized:
  - Financial amounts (invoice totals, order amounts)
  - Document references (FA2024-001, etc.)
  - Product catalog
  - The admin account specified by keep-admin
  - Files on disk (documents, attachments)
  - Extrafields (custom fields)");
    }

    public function handle(): void
    {
        global $db, $conf, $mysoc;

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        $keepAdmin = $this->getParam('keep-admin') ?? DolibarrHelper::findFirstSuperAdmin();
        if ( ! $keepAdmin) {
            $this->error("No super admin found. Use keep-admin=LOGIN to specify which admin to preserve.");
            return;
        }

        $summary = AnonymizeHelper::getDryRunSummary($db, $keepAdmin);

        if ( ! $this->hasFlag('confirm')) {
            $this->showDryRun($summary, $keepAdmin);
            return;
        }

        $this->showWarning($summary, $keepAdmin, $conf, $mysoc);

        $this->rawOutput("\n");
        $this->rawOutput("  Tapez YES en majuscules pour confirmer : ");
        $answer = trim(fgets(STDIN));

        if ('YES' !== $answer) {
            $this->info("Abandon.");
            return;
        }

        $this->rawOutput("\n");
        $this->execute($db, $conf, $summary, $keepAdmin);
    }

    private function showDryRun(array $summary, string $keepAdmin): void
    {
        $this->info("DRY RUN - Apercu de l'anonymisation\n");
        $this->info("Admin preserve : {$keepAdmin}\n");

        $this->rawOutput("  Donnees qui seraient anonymisees :\n\n");
        $this->printSummary($summary);

        $this->rawOutput("\n");
        $this->info("Pour executer, ajoutez le flag --confirm");
    }

    private function showWarning(array $summary, string $keepAdmin, object $conf, ?object $mysoc): void
    {
        $companyName = (isset($mysoc) && ! empty($mysoc->name)) ? $mysoc->name : '(non configure)';
        $dbHost = $conf->db->host ?? '(inconnu)';
        $dbName = $conf->db->name ?? '(inconnu)';
        $dbPort = $conf->db->port ?? '';
        $serverInfo = $dbHost.($dbPort ? ':'.$dbPort : '');

        $this->rawOutput("\n");
        $this->rawOutput("  ╔══════════════════════════════════════════════════════════════╗\n");
        $this->rawOutput("  ║                    ATTENTION - DANGER                        ║\n");
        $this->rawOutput("  ╠══════════════════════════════════════════════════════════════╣\n");
        $this->rawOutput("  ║                                                              ║\n");
        $this->rawOutput("  ║  Cette operation va ANONYMISER DEFINITIVEMENT toutes les      ║\n");
        $this->rawOutput("  ║  donnees personnelles de cette base Dolibarr.                 ║\n");
        $this->rawOutput("  ║                                                              ║\n");
        $this->rawOutput("  ║  Cette action est IRREVERSIBLE.                               ║\n");
        $this->rawOutput("  ║                                                              ║\n");
        $this->rawOutput("  ╚══════════════════════════════════════════════════════════════╝\n");
        $this->rawOutput("\n");

        $this->rawOutput("  Societe    : {$companyName}\n");
        $this->rawOutput("  Serveur    : {$serverInfo}\n");
        $this->rawOutput("  Base       : {$dbName}\n");
        $this->rawOutput("  Admin pres.: {$keepAdmin}\n");
        $this->rawOutput("\n");

        $this->rawOutput("  Donnees qui vont etre anonymisees :\n\n");
        $this->printSummary($summary);
    }

    private function printSummary(array $summary): void
    {
        $labels = [
            'thirdparties'      => 'Tiers',
            'contacts'          => 'Contacts',
            'users'             => 'Utilisateurs',
            'members'           => 'Adherents',
            'bank_rib'          => 'RIB tiers',
            'bank_accounts'     => 'Comptes bancaires',
            'external_accounts' => 'Comptes externes',
            'communications'    => 'Communications',
            'mailing_targets'   => 'Cibles mailing',
            'employment'        => 'Contrats emploi',
            'email_collectors'  => 'Collecteurs email',
            'blocked_log'       => 'Blocked log',
            'sessions'          => 'Sessions',
            'note_tables'       => 'Tables avec notes',
        ];

        foreach ($labels as $key => $label) {
            if (isset($summary[$key]) && $summary[$key] > 0) {
                $value = $summary[$key];
                $suffix = ('note_tables' === $key) ? ' tables' : ' lignes';
                $this->rawOutput("    ".str_pad($label, 22).$value.$suffix."\n");
            }
        }
    }

    private function execute(object $db, object $conf, array $summary, string $keepAdmin): void
    {
        $wasInMaintenanceMode = ! empty($conf->global->MAIN_ONLY_LOGIN_ALLOWED);
        if ( ! $wasInMaintenanceMode) {
            require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
            $this->info("Activation du mode maintenance...");
            dolibarr_set_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $keepAdmin, 'chaine', 0, 'Anonymization in progress', $conf->entity);
        }

        $db->begin();

        try {
            if ( ! $this->hasFlag('skip-thirdparties')) {
                $count = AnonymizeHelper::anonymizeThirdparties($db);
                $this->success("Tiers              : {$count} lignes anonymisees");

                $count = AnonymizeHelper::anonymizeContacts($db);
                $this->success("Contacts           : {$count} lignes anonymisees");
            }

            if ( ! $this->hasFlag('skip-users')) {
                $count = AnonymizeHelper::anonymizeUsers($db, $keepAdmin);
                $this->success("Utilisateurs       : {$count} lignes anonymisees");

                $count = AnonymizeHelper::anonymizeEmployment($db);
                $this->success("Contrats emploi    : {$count} lignes anonymisees");
            }

            if ( ! $this->hasFlag('skip-members')) {
                $count = AnonymizeHelper::anonymizeMembers($db);
                $this->success("Adherents          : {$count} lignes anonymisees");
            }

            if ( ! $this->hasFlag('skip-bank')) {
                $count = AnonymizeHelper::anonymizeBankData($db);
                $this->success("Donnees bancaires  : {$count} lignes anonymisees");

                $count = AnonymizeHelper::anonymizeExternalAccounts($db);
                $this->success("Comptes externes   : {$count} lignes anonymisees");
            }

            $count = AnonymizeHelper::anonymizeCommunications($db);
            $this->success("Communications     : {$count} lignes anonymisees");

            $count = AnonymizeHelper::anonymizeMailings($db);
            $this->success("Mailings           : {$count} lignes anonymisees");

            $count = AnonymizeHelper::anonymizeEmailCollectors($db);
            $this->success("Collecteurs email  : {$count} lignes anonymisees");

            $this->rawOutput("\n");
            $this->info("Nettoyage des notes...");
            $noteResults = AnonymizeHelper::clearNotes($db);
            $notesCleared = 0;
            foreach ($noteResults as $table => $count) {
                if ($count > 0) {
                    $this->rawOutput("  {$table}: {$count} lignes\n");
                    $notesCleared += $count;
                }
            }
            $this->success("Notes              : {$notesCleared} lignes nettoyees");

            $this->rawOutput("\n");
            $count = AnonymizeHelper::truncateBlockedLog($db);
            $this->success("Blocked log        : {$count} lignes supprimees");

            $count = AnonymizeHelper::truncateSessions($db);
            $this->success("Sessions           : {$count} lignes supprimees");

            $db->commit();

            $this->rawOutput("\n");
            $this->success("Anonymisation terminee avec succes !");
            $this->info("Rappel : les fichiers sur disque (documents, pieces jointes) n'ont pas ete modifies.");

        } catch (Exception $e) {
            $db->rollback();
            $this->error("Erreur : ".$e->getMessage());
            $this->error("Toutes les modifications ont ete annulees (rollback).");
        } finally {
            if ( ! $wasInMaintenanceMode) {
                dolibarr_del_const($db, 'MAIN_ONLY_LOGIN_ALLOWED', $conf->entity);
                $this->info("Mode maintenance desactive.");
            }
        }
    }
}
