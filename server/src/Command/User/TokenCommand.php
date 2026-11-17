<?php

namespace Dolicli\Command\User;

use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Dolibarr\Dolibarr;

/**
 * Key of the native REST API of Dolibarr (DOLAPIKEY). That API is legacy for the team:
 * kept for third-party installations that already use it.
 */
final class TokenCommand extends UserCommand
{
    public function name(): string
    {
        return 'user token';
    }

    public function summary(): string
    {
        return 'Generate the native REST API key of a user';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            $this->loginParam(),
            Param::flag('force', 'Replace the existing key (the old one stops working)'),
        ];
    }

    public function help(): string
    {
        return "The key is displayed once, when it is generated: it cannot be displayed again.\n"
            . 'It is the key of the native REST API of Dolibarr (legacy): prefer a dedicated authentication for new integrations.';
    }

    public function execute(Context $ctx): array
    {
        $login = (string) $ctx->value('login');
        $user = $this->target($login);
        $hadKey = !empty($user->api_key);
        if ($hadKey && !$ctx->flag('force')) {
            throw Failure::operation('API_KEY_EXISTS', "User '{$login}' already has an API key: it was NOT modified. Use --force to replace it.");
        }
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'login' => $login, 'actions' => [($hadKey ? 'Replace' : 'Generate') . " the API key of '{$login}'"]];
        }

        $acting = Dolibarr::actingUser($ctx);
        $key = bin2hex(random_bytes(20));
        $user->api_key = $key;
        if ($user->update($acting) <= 0) {
            throw Failure::operation('USER_UPDATE_FAILED', "Failed to generate the API key of '{$login}': " . self::errorsOf($user));
        }

        return ['login' => $login, 'api_key' => $key, 'replaced' => $hadKey];
    }

    public function renderText(array $data): string
    {
        return "API key generated for {$data['login']}" . ($data['replaced'] ? ' (the previous key no longer works)' : '') . ":\n"
            . $data['api_key'] . "\nSave it now: it cannot be displayed again.\n";
    }
}
