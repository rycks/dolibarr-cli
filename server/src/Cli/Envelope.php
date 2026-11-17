<?php

namespace Dolicli\Cli;

use Dolicli\Version;

/**
 * Common JSON envelope of every command (schema/envelope.schema.json).
 */
final class Envelope
{
    /**
     * @param array<string, mixed>|null $instance
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $warnings
     * @param array<int, array<string, mixed>> $errors
     * @return array<string, mixed>
     */
    public static function build(string $command, $instance, string $actingUser, array $data, array $warnings, array $errors, float $start): array
    {
        return [
            'schema_version' => Version::ENVELOPE_SCHEMA,
            'dolicli_version' => Version::VERSION,
            'command' => $command,
            'instance' => $instance,
            'acting_user' => '' === $actingUser ? null : $actingUser,
            'ok' => [] === $errors,
            'data' => Json::object($data),
            'warnings' => $warnings,
            'errors' => $errors,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(Failure $failure): array
    {
        $error = ['code' => $failure->errorCode(), 'message' => $failure->getMessage()];
        if ([] !== $failure->details()) {
            $error['details'] = $failure->details();
        }

        return $error;
    }
}
