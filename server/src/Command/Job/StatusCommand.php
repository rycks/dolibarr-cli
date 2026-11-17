<?php

namespace Dolicli\Command\Job;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Param;
use Dolicli\Job\Jobs;

final class StatusCommand extends Command
{
    public function name(): string
    {
        return 'job status';
    }

    public function summary(): string
    {
        return 'State, log and result of a detached job (all jobs without id=)';
    }

    public function params(): array
    {
        return [
            Param::value('id', 'ID', 'Identifier of the job')->matching(Jobs::ID_PATTERN),
            Param::value('lines', 'N', 'Lines of log (default 50)')->integer(),
        ];
    }

    public function execute(Context $ctx): array
    {
        $jobs = new Jobs($ctx->dataRoot());
        $id = (string) $ctx->value('id');
        if ('' === $id) {
            return ['jobs' => $jobs->all()];
        }

        return ['job' => $jobs->status($id, (int) ($ctx->value('lines') ?: 50))];
    }

    public function renderText(array $data): string
    {
        if (isset($data['jobs'])) {
            $out = '';
            foreach ($data['jobs'] as $job) {
                $out .= str_pad((string) $job['id'], 30) . str_pad((string) $job['state'], 10) . (isset($job['command']) ? $job['command'] : '') . "\n";
            }

            return '' === $out ? "No job.\n" : $out;
        }
        $job = $data['job'];

        return "Job {$job['id']}: {$job['state']}" . (isset($job['exit_code']) ? " (exit {$job['exit_code']})" : '') . "\n" . implode("\n", $job['log']) . "\n";
    }
}
