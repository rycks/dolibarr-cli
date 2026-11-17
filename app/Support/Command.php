<?php

declare(strict_types=1);

namespace App\Support;

use App\Util\DolibarrHelper;
use Minicli\Command\CommandController;
use User;

/**
 * Base class of every dolicli command: error reporting with exit code and
 * syslog, tolerant parameter reading, prompts.
 */
abstract class Command extends CommandController
{
    /**
     * Report a failure: message on screen, reason in the Dolibarr log, exit code set.
     */
    protected function fail(string $message, int $code = ExitStatus::FAILURE): void
    {
        $this->error($message);
        DolibarrHelper::log($message, LOG_ERR);
        ExitStatus::set($code);
    }

    /**
     * Read a key=value parameter, accepting both `name=value` and `--name=value`.
     */
    protected function param(string $name): ?string
    {
        return $this->getParam($name) ?? $this->getParam('--'.$name);
    }

    protected function hasOption(string $name): bool
    {
        return null !== $this->param($name);
    }

    /**
     * Positional action after the subcommand: `./dolibarr system maintenance <action>`.
     * Minicli removes key=value params and --flags from the positional list,
     * so index 3 is always the first word after the subcommand.
     */
    protected function action(): ?string
    {
        return $this->getArgs()[3] ?? null;
    }

    /**
     * Check that Dolibarr is loaded and connected to its database.
     */
    protected function requireDolibarr(): bool
    {
        global $db;

        if ( ! defined('DOL_DOCUMENT_ROOT')) {
            $this->fail('DOL_DOCUMENT_ROOT is not defined: check the DOLPATH environment variable.');

            return false;
        }

        if ( ! isset($db) || ! is_object($db) || empty($db->connected)) {
            $this->fail('Dolibarr database connection is not available: check DOLPATH and conf/conf.php.');

            return false;
        }

        return true;
    }

    /**
     * Ask a yes/no question. `--yes` answers yes; a closed STDIN answers no.
     */
    protected function confirm(string $question): bool
    {
        if ($this->hasFlag('yes')) {
            return true;
        }

        $answer = $this->readLine($question.' (yes/no) ');
        if (null === $answer) {
            $this->fail('No answer on standard input: use --yes to run without prompt.');

            return false;
        }

        return in_array(mb_strtolower(trim($answer)), ['yes', 'y', 'oui', 'o'], true);
    }

    /**
     * Read one line from STDIN, null on end of input.
     */
    protected function readLine(string $prompt): ?string
    {
        $this->rawOutput($prompt);
        $line = fgets(STDIN);
        if (false === $line) {
            $this->rawOutput("\n");

            return null;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Read a secret from STDIN without echoing it when STDIN is a terminal.
     */
    protected function readSecret(string $prompt): ?string
    {
        $isTty = function_exists('posix_isatty') && posix_isatty(STDIN);
        if ($isTty) {
            shell_exec('stty -echo');
        }

        try {
            $value = $this->readLine($prompt);
        } finally {
            if ($isTty) {
                shell_exec('stty echo');
                $this->rawOutput("\n");
            }
        }

        return $value;
    }

    /**
     * Secret given on STDIN (`--password-stdin`) or asked interactively until not empty.
     */
    protected function askSecret(string $prompt): ?string
    {
        if ($this->hasFlag('password-stdin')) {
            $line = fgets(STDIN);
            if (false === $line || '' === trim($line)) {
                $this->fail('--password-stdin given but nothing was read on standard input.');

                return null;
            }

            return rtrim($line, "\r\n");
        }

        do {
            $value = $this->readSecret($prompt);
            if (null === $value) {
                $this->fail('No password on standard input.');

                return null;
            }
        } while ('' === trim($value));

        return $value;
    }

    /**
     * User acting for the command (first active super admin), loaded into the global $user.
     */
    protected function actingUser(): ?User
    {
        global $user;

        if (isset($user) && $user instanceof User && $user->id > 0) {
            return $user;
        }

        $admin = DolibarrHelper::getFirstSuperAdminUser();
        if (null === $admin) {
            $this->fail('No active super admin user found: at least one is needed to act on Dolibarr.');

            return null;
        }
        $user = $admin;

        return $admin;
    }
}
