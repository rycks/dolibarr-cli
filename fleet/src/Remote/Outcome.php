<?php

declare(strict_types=1);

namespace Dolifleet\Remote;

use Dolifleet\Inventory\Instance;

/**
 * Result of dolicli on one instance: its envelope, or a failure of the transport.
 * Data received from a server is untrusted: only decoded JSON is kept, never evaluated.
 */
final class Outcome
{
    /**
     * @param array<string, mixed>|null $envelope
     */
    public function __construct(
        public readonly Instance $instance,
        public readonly int $exitCode,
        public readonly ?array $envelope,
        public readonly string $error,
        public readonly string $stderr,
        public readonly bool $reached,
    ) {
    }

    public static function from(Instance $instance, Result $result): self
    {
        $envelope = json_decode($result->stdout, true);
        if (is_array($envelope) && array_key_exists('ok', $envelope)) {
            $error = '';
            if (true !== $envelope['ok']) {
                $first = $envelope['errors'][0] ?? [];
                $error = (string) ($first['code'] ?? 'ERROR').': '.(string) ($first['message'] ?? '');
            }

            return new self($instance, $result->exitCode, $envelope, $error, $result->stderr, true);
        }
        if (is_array($envelope)) {
            // Raw documents (state file, discovery) are not envelopes.
            return new self($instance, $result->exitCode, ['ok' => 0 === $result->exitCode, 'data' => $envelope, 'errors' => [], 'warnings' => []], '', $result->stderr, true);
        }
        if ($result->connectionFailed()) {
            return new self($instance, $result->exitCode, null, 'SSH_FAILED: '.self::firstLine($result->stderr), $result->stderr, false);
        }
        if (str_contains($result->stderr, 'sudo: ')) {
            return new self($instance, $result->exitCode, null, 'SUDO_FAILED: '.self::firstLine($result->stderr).' (sudo -n needs a NOPASSWD rule, see the documentation)', $result->stderr, true);
        }

        return new self($instance, $result->exitCode, null, 'NO_ENVELOPE: exit '.$result->exitCode.', '.self::firstLine($result->stderr.' '.$result->stdout), $result->stderr, true);
    }

    public function ok(): bool
    {
        return null !== $this->envelope && true === $this->envelope['ok'];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->envelope['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    private static function firstLine(string $text): string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '');

        return mb_substr(strtok($text, "\n") ?: $text, 0, 300);
    }
}
