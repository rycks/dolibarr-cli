<?php

namespace Dolicli\Command\User;

use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\TextRenderer;
use Dolicli\Dolibarr\Dolibarr;
use User;

final class ListCommand extends UserCommand
{
    public function name(): string
    {
        return 'user list';
    }

    public function summary(): string
    {
        return 'List users';
    }

    public function help(): string
    {
        return 'Columns: login, last name, first name, email, status (enabled/disabled), admin (no, admin, superadmin).';
    }

    public function execute(Context $ctx): array
    {
        Dolibarr::requireCore('user/class/user.class.php');
        $users = new User(Dolibarr::db());
        if ($users->fetchAll('ASC', 't.login') < 0) {
            throw Failure::operation('USER_LIST_FAILED', "Can't fetch the user list: " . $users->error);
        }
        $rows = [];
        foreach ($users->users as $user) {
            $rows[] = [
                'login' => (string) $user->login,
                'lastname' => (string) $user->lastname,
                'firstname' => (string) $user->firstname,
                'email' => (string) $user->email,
                'status' => self::isEnabled($user) ? 'enabled' : 'disabled',
                'admin' => self::adminLevel($user),
            ];
        }

        return ['users' => $rows, 'count' => count($rows)];
    }

    public function renderText(array $data): string
    {
        return TextRenderer::records($data['users']);
    }
}
