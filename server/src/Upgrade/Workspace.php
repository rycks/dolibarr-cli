<?php

namespace Dolicli\Upgrade;

use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;
use Dolicli\System\Files;
use Dolicli\System\Log;

/**
 * Files of an upgrade on the server, in DOL_DATA_ROOT/dolicli/upgrade/: plan with its
 * progress, plan lease, uploaded archives. Another administrator can resume from them.
 */
final class Workspace
{
    /** @var string */
    private $dir;

    public function __construct(string $dataRoot)
    {
        $this->dir = rtrim($dataRoot, '/') . '/dolicli/upgrade';
    }

    public static function current(): self
    {
        return new self(DOL_DATA_ROOT);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function ensure()
    {
        Files::ensureDir($this->dir, 0750);
    }

    public function planFile(): string
    {
        return $this->dir . '/plan.json';
    }

    public function leaseFile(): string
    {
        return $this->dir . '/lease.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        return self::readJson($this->planFile());
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function savePlan(array $plan)
    {
        $this->ensure();
        $plan['updated_at'] = date('c');
        Files::writeAtomic($this->planFile(), Json::encode($plan) . "\n", 0640);
    }

    /**
     * Record the state of one step of the plan (pending, running, done, failed).
     */
    public function recordStep(string $stepId, string $state, string $message = '')
    {
        $plan = $this->plan();
        if ([] === $plan) {
            return;
        }
        if (!isset($plan['progress']) || !is_array($plan['progress'])) {
            $plan['progress'] = [];
        }
        $plan['progress'][$stepId] = ['state' => $state, 'at' => date('c'), 'message' => $message];
        $this->savePlan($plan);
    }

    /**
     * Current lease of the plan, empty when none or expired.
     *
     * @return array<string, mixed>
     */
    public function lease(): array
    {
        $lease = self::readJson($this->leaseFile());
        if ([] === $lease || !isset($lease['expires_at_unix']) || (int) $lease['expires_at_unix'] < time()) {
            return [];
        }

        return $lease;
    }

    /**
     * Take or renew the lease of the plan for $holder. Failure when another holder has it.
     *
     * @return array<string, mixed>
     */
    public function takeLease(string $holder, int $seconds): array
    {
        $this->ensure();
        $current = $this->lease();
        if ([] !== $current && $current['holder'] !== $holder) {
            throw new Failure(
                'PLAN_LOCKED',
                "The upgrade plan is held by {$current['holder']} until {$current['expires_at']}.",
                \Dolicli\Cli\ExitCode::LOCKED,
                ['lease' => $current]
            );
        }
        $lease = [
            'holder' => $holder,
            'started_at' => [] !== $current ? $current['started_at'] : date('c'),
            'expires_at' => date('c', time() + $seconds),
            'expires_at_unix' => time() + $seconds,
        ];
        Files::writeAtomic($this->leaseFile(), Json::encode($lease) . "\n", 0640);

        return $lease;
    }

    public function releaseLease(string $holder)
    {
        $current = $this->lease();
        if ([] !== $current && $current['holder'] !== $holder) {
            throw new Failure('PLAN_LOCKED', "The upgrade plan is held by {$current['holder']}, not by {$holder}.", \Dolicli\Cli\ExitCode::LOCKED);
        }
        if (is_file($this->leaseFile()) && !@unlink($this->leaseFile())) {
            Log::error('cannot remove ' . $this->leaseFile());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        $content = @file_get_contents($file);
        if (!is_string($content)) {
            return [];
        }
        $data = Json::decode($content);

        return is_array($data) ? $data : [];
    }
}
