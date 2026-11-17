<?php

namespace Dolicli\Command\User;

use Dolicli\Cli\Command;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;
use User;

/**
 * Shared helpers of the user commands.
 */
abstract class UserCommand extends Command
{
    protected function loginParam(): Param
    {
        return Param::value('login', 'LOGIN', 'Dolibarr login')->required();
    }

    /**
     * User to act on, Failure when absent.
     */
    protected function target(string $login): User
    {
        return Dolibarr::user($login);
    }

    protected static function errorsOf(User $user): string
    {
        $errors = array_filter(array_merge([(string) $user->error], (array) $user->errors));

        return [] === $errors ? 'unknown error' : strip_tags(implode(', ', array_unique($errors)));
    }

    protected static function adminLevel(User $user): string
    {
        if (1 !== (int) $user->admin) {
            return 'no';
        }

        return 0 === (int) $user->entity ? 'superadmin' : 'admin';
    }

    protected static function isEnabled(User $user): bool
    {
        return 1 === (int) (isset($user->statut) ? $user->statut : $user->status);
    }
}
