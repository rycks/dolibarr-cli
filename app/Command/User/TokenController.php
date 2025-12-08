<?php

declare(strict_types=1);

namespace App\Command\User;

use Minicli\Command\CommandController;

class TokenController extends CommandController
{
    public function usage(): void
    {
        $this->info("Generate API token for a Dolibarr user

Usage:
  ./dolibarr user token login=USERNAME [--force]

Parameters:
  login=USERNAME     - User login for which to generate the API token

Options:
  --force            - Force regeneration even if token already exists
  --help             - Display this help message

Examples:
  ./dolibarr user token login=admin
  ./dolibarr user token login=jdoe --force

Note: API tokens are used for REST API authentication in Dolibarr.");
    }

    public function handle(): void
    {
        global $db, $conf;

        $this->info('Generate API token for Dolibarr user');

        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        // Check mandatory parameter
        if (!$this->hasParam('login')) {
            $this->error("Parameter 'login' is required!");
            $this->usage();
            return;
        }

        $login = $this->getParam('login');
        $force = $this->hasFlag('force');

        // Load user class
        require_once DOL_DOCUMENT_ROOT . "/user/class/user.class.php";
        $user = new \User($db);

        // Fetch the user
        $result = $user->fetch('', $login);

        if ($result <= 0) {
            $this->error("User '$login' not found!");
            $this->info("Please check the login and try again.");
            return;
        }

        $this->display("User found: " . $user->getFullName($user));

        // Check if token already exists
        if (!empty($user->api_key)) {
            $this->error("\nWARNING: An API token already exists for user '$login'!");
            $this->display("Current token: " . $user->api_key);

            if (!$force) {
                $this->info("\nTo regenerate the token, use the --force option:");
                $this->info("  ./dolibarr user token login=$login --force");
                $this->error("\nOperation cancelled. Existing token was NOT modified.");
                return;
            } else {
                $this->info("\n--force option detected. Generating new token...");
            }
        }

        // Generate new API token
        $newToken = $this->generateToken();

        // Update user with new token
        $user->api_key = $newToken;
        $updateResult = $user->update($user);

        if ($updateResult > 0) {
            $this->success("\nAPI token generated successfully!");
            $this->display("═══════════════════════════════════════════════════════════");
            $this->display("user   $login");
            $this->display("Token: $newToken");
            $this->display("═══════════════════════════════════════════════════════════");

            if ($force && !empty($user->api_key)) {
                $this->info("\nPrevious token has been replaced.");
            }

            $this->info("\nYou can now use this token for API authentication:");
            $this->info("  DOLAPIKEY=$newToken");
            $this->error("\nIMPORTANT: Save this token securely. You won't be able to see it again!");
        } else {
            $this->error("Failed to generate API token for user '$login'");
            if ($user->error) {
                $this->error("Error: " . $user->error);
            }
        }
    }

    /**
     * Generate a secure random token
     *
     * @return string
     */
    private function generateToken(): string
    {
        // Generate a secure random token (40 characters)
        // Same format as Dolibarr's native token generation
        return bin2hex(random_bytes(20));
    }

    public function required(): array
    {
        return [];
    }
}
