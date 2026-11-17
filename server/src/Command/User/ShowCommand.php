<?php

namespace Dolicli\Command\User;

use Dolicli\Cli\Context;
use Dolicli\Dolibarr\Dolibarr;
use UserGroup;

final class ShowCommand extends UserCommand
{
    public function name(): string
    {
        return 'user show';
    }

    public function summary(): string
    {
        return 'Display user details';
    }

    public function params(): array
    {
        return [$this->loginParam()];
    }

    public function help(): string
    {
        return 'The API key is never displayed, only whether one is set.';
    }

    public function execute(Context $ctx): array
    {
        global $langs;

        $user = $this->target((string) $ctx->value('login'));
        Dolibarr::requireCore('user/class/usergroup.class.php');

        if (method_exists($user, 'loadRights')) {
            $user->loadRights();
        } else {
            $user->getrights();
        }
        $groups = [];
        $groupObject = new UserGroup(Dolibarr::db());
        $list = $groupObject->listGroupsForUser($user->id, false);
        if (is_array($list)) {
            foreach ($list as $group) {
                $groups[] = (string) (isset($group->name) ? $group->name : $group->nom);
            }
        }

        return [
            'id' => (int) $user->id,
            'login' => (string) $user->login,
            'firstname' => (string) $user->firstname,
            'lastname' => (string) $user->lastname,
            'full_name' => (string) $user->getFullName($langs),
            'email' => (string) $user->email,
            'office_phone' => (string) $user->office_phone,
            'mobile_phone' => (string) $user->user_mobile,
            'status' => self::isEnabled($user) ? 'enabled' : 'disabled',
            'admin' => self::adminLevel($user),
            'entity' => (int) $user->entity,
            'api_key' => empty($user->api_key) ? 'not set' : 'set',
            'created_at' => self::date($user->datec),
            'modified_at' => self::date($user->datem),
            'last_login_at' => self::date($user->datelastlogin),
            'previous_login_at' => self::date($user->datepreviouslogin),
            'job' => (string) $user->job,
            'address' => trim((string) $user->address),
            'zip' => (string) $user->zip,
            'town' => (string) $user->town,
            'country_code' => (string) $user->country_code,
            'permissions' => self::countRights($user->rights),
            'groups' => $groups,
        ];
    }

    /**
     * @param mixed $timestamp
     * @return string|null
     */
    private static function date($timestamp)
    {
        if (empty($timestamp)) {
            return null;
        }

        return date('c', is_numeric($timestamp) ? (int) $timestamp : (int) strtotime((string) $timestamp));
    }

    /**
     * @param mixed $rights
     */
    private static function countRights($rights): int
    {
        $count = 0;
        if (is_object($rights) || is_array($rights)) {
            foreach ((array) $rights as $value) {
                $count += (is_object($value) || is_array($value)) ? self::countRights($value) : (1 === (int) $value ? 1 : 0);
            }
        }

        return $count;
    }
}
