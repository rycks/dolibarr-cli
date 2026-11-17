<?php

namespace Dolicli\Command;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Version;

final class VersionCommand extends Command
{
    public function name(): string
    {
        return 'version';
    }

    public function summary(): string
    {
        return 'Version of dolicli, of PHP and of the data schemas';
    }

    public function needsDolibarr(): bool
    {
        return false;
    }

    public function allowedAsRoot(): bool
    {
        return true;
    }

    public function execute(Context $ctx): array
    {
        return [
            'dolicli_version' => Version::VERSION,
            'body_sha256' => Version::BODY_SHA256,
            'php_version' => PHP_VERSION,
            'php_binary' => PHP_BINARY,
            'envelope_schema' => Version::ENVELOPE_SCHEMA,
            'state_schema' => Version::STATE_SCHEMA,
            'os' => php_uname('s') . ' ' . php_uname('r'),
        ];
    }

    public function renderText(array $data): string
    {
        return 'dolicli ' . $data['dolicli_version'] . "\n"
            . 'PHP ' . $data['php_version'] . ' (' . $data['php_binary'] . ")\n"
            . 'Schemas: envelope ' . $data['envelope_schema'] . ', state ' . $data['state_schema'] . "\n"
            . ('' !== $data['body_sha256'] ? 'Body SHA256: ' . $data['body_sha256'] . "\n" : '');
    }
}
