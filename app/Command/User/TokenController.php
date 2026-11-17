<?php

declare(strict_types=1);

namespace App\Command\User;

use App\Support\Command;
use User;

class TokenController extends Command
{
    public function summary(): string
    {
        return 'Generate a REST API key';
    }

    public function usage(): void
    {
        $this->info('Generate the REST API key of a Dolibarr user

Usage:
  ./dolibarr user token login=USERNAME [--force]

Parameters:
  login=USERNAME     - User login for which to generate the API key

Options:
  --force            - Replace the existing key (the old one stops working)
  --help             - Display this help message

The key is displayed once, when it is generated: it cannot be displayed again.');
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
        $target = new User($db);
        $result = $target->fetch(0, $login);
        if ($result <= 0) {
            $this->fail(0 === $result ? "User '{$login}' not found." : "Cannot fetch user '{$login}': ".$target->error);

            return;
        }

        $hadKey = ! empty($target->api_key);
        if ($hadKey && ! $this->hasFlag('force')) {
            $this->fail("User '{$login}' already has an API key: it was NOT modified. Use --force to replace it.");

            return;
        }

        $actingUser = $this->actingUser() ?? $target;
        $newToken = bin2hex(random_bytes(20));
        $target->api_key = $newToken;
        if ($target->update($actingUser) <= 0) {
            $this->fail("Failed to generate the API key of '{$login}': ".$target->error);

            return;
        }

        $this->success("API key generated for {$login}".($hadKey ? ' (the previous key no longer works)' : '').':');
        $this->rawOutput($newToken."\n");
        $this->info('Save it now: it cannot be displayed again.');
    }
}
