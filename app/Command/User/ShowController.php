<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Support\Command;
use User;
use UserGroup;

class ShowController extends Command
{
    private const RULE = "===========================================================\n";

    public function usage(): void
    {
        $this->info('Display detailed information about a Dolibarr user

Usage:
  ./dolibarr user show login=USERNAME

Parameters:
  login=USERNAME     - User login to display

Options:
  --help             - Display this help message');
    }

    public function handle(): void
    {
        global $db;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        $login = $this->param('login');
        if (null === $login || '' === $login) {
            $this->usage();
            $this->fail("Parameter 'login' is required.");

            return;
        }

        if ( ! $this->requireDolibarr()) {
            return;
        }

        require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
        require_once DOL_DOCUMENT_ROOT.'/user/class/usergroup.class.php';
        $u = new User($db);
        $result = $u->fetch(0, $login);
        if ($result <= 0) {
            $this->fail(0 === $result ? "User '{$login}' not found." : "Cannot fetch user '{$login}': ".$u->error);

            return;
        }

        $isAdmin = 1 === (int) $u->admin;
        $isSuperAdmin = $isAdmin && 0 === (int) $u->entity;

        $this->rawOutput("\n".self::RULE."  USER DETAILS\n".self::RULE."\n");

        $this->section('BASIC INFORMATION', [
            'ID' => (string) $u->id,
            'Login' => (string) $u->login,
            'First name' => $this->orNotSet($u->firstname),
            'Last name' => $this->orNotSet($u->lastname),
            'Full name' => $u->getFullName($GLOBALS['langs']),
        ]);

        $this->section('CONTACT INFORMATION', [
            'Email' => $this->orNotSet($u->email),
            'Phone (office)' => $this->orNotSet($u->office_phone),
            'Phone (mobile)' => $this->orNotSet($u->user_mobile),
            'Fax' => $this->orNotSet($u->office_fax),
        ]);

        $this->section('STATUS & PERMISSIONS', [
            'Status' => 1 === (int) $u->statut ? 'Enabled' : 'Disabled',
            'Admin rights' => $isAdmin ? 'Yes' : 'No',
            'Super admin' => $isSuperAdmin ? 'Yes (all entities)' : 'No',
            'Entity' => (string) $u->entity,
        ]);

        $this->section('AUTHENTICATION', [
            'API key' => empty($u->api_key) ? 'Not set' : 'Set',
            'Auth mode' => $u->auth_mode ?: 'dolibarr',
        ]);

        $this->section('ACCOUNT DATES', array_filter([
            'Created' => $u->datec ? dol_print_date($u->datec, 'dayhour') : null,
            'Modified' => $u->datem ? dol_print_date($u->datem, 'dayhour') : null,
            'Last login' => $u->datelastlogin ? dol_print_date($u->datelastlogin, 'dayhour') : '(never)',
            'Previous login' => $u->datepreviouslogin ? dol_print_date($u->datepreviouslogin, 'dayhour') : null,
        ], static fn ($v): bool => null !== $v));

        $this->section('ADDITIONAL INFORMATION', [
            'Job' => $this->orNotSet($u->job),
            'Signature' => $u->signature ? 'Set' : '(not set)',
            'Note (public)' => $u->note_public ? 'Set' : '(not set)',
            'Note (private)' => $u->note_private ? 'Set' : '(not set)',
        ]);

        $location = trim(($u->zip ?: '').' '.($u->town ?: ''));
        $address = array_filter([
            'Address' => $u->address ?: null,
            'City' => '' !== $location ? $location : null,
            'State' => $u->state_code ?: null,
            'Country' => $u->country_code ?: null,
        ]);
        if ([] !== $address) {
            $this->section('ADDRESS', $address);
        }

        $u->getrights();
        $groupObject = new UserGroup($db);
        $groups = $groupObject->listGroupsForUser($u->id, false);
        $groupNames = is_array($groups) ? array_map(static fn ($g): string => (string) $g->name, $groups) : [];

        $this->rawOutput("RIGHTS & GROUPS:\n");
        $this->rawOutput($this->line('Permissions', $this->countRights($u->rights ?? null).' permission(s)'));
        if ([] === $groupNames) {
            $this->rawOutput($this->line('Groups', 'No groups'));
        } else {
            $this->rawOutput($this->line('Groups', count($groupNames).' group(s)'));
            foreach ($groupNames as $name) {
                $this->rawOutput(str_repeat(' ', 19).'- '.$name."\n");
            }
        }

        $this->rawOutput("\n".self::RULE);
    }

    /**
     * @param  array<string, string>  $lines
     */
    private function section(string $title, array $lines): void
    {
        $this->rawOutput($title.":\n");
        foreach ($lines as $label => $value) {
            $this->rawOutput($this->line($label, $value));
        }
        $this->rawOutput("\n");
    }

    private function line(string $label, string $value): string
    {
        return '  '.str_pad($label.':', 17).$value."\n";
    }

    private function orNotSet(mixed $value): string
    {
        return (null === $value || '' === $value) ? '(not set)' : (string) $value;
    }

    private function countRights(mixed $rights): int
    {
        $count = 0;
        if (is_object($rights)) {
            foreach ((array) $rights as $value) {
                $count += is_object($value) ? $this->countRights($value) : (1 === (int) $value ? 1 : 0);
            }
        }

        return $count;
    }
}
