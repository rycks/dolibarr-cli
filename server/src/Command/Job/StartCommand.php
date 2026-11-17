<?php

namespace Dolicli\Command\Job;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Json;
use Dolicli\Cli\Param;
use Dolicli\Job\Jobs;
use Dolicli\System\Paths;

/**
 * Start a long operation detached from the session (setsid): backup of a large database,
 * migration. The body comes from the stream of the pilot, or from the installed mode.
 */
final class StartCommand extends Command
{
    public function name(): string
    {
        return 'job start';
    }

    public function summary(): string
    {
        return 'Start a dolicli command detached from the session (long operations)';
    }

    public function category(): string
    {
        return self::WRITE;
    }

    public function params(): array
    {
        return [
            Param::value('id', 'ID', 'Identifier of the job')->required()->matching(Jobs::ID_PATTERN),
            Param::value('args', 'JSON', 'Arguments of the dolicli command, JSON list')->required(),
        ];
    }

    public function help(): string
    {
        return "The job runs in DOL_DATA_ROOT/dolicli/jobs/<id>/ (copy of the body, log, result, status) and\n"
            . 'survives the end of the SSH session. Follow it with dolicli job status id=<id>.';
    }

    public function execute(Context $ctx): array
    {
        $arguments = Json::decode((string) $ctx->value('args'));
        if (!is_array($arguments) || [] === $arguments) {
            throw Failure::usage('INVALID_VALUE', 'args must be a non-empty JSON list of strings.');
        }
        $arguments = array_map('strval', array_values($arguments));
        if ($ctx->dryRun()) {
            return ['dry_run' => true, 'actions' => ['Start detached: dolicli ' . implode(' ', array_slice($arguments, 0, 3))]];
        }
        $body = '';
        if (isset($GLOBALS['DOLICLI_FILES']['body'])) {
            $body = (string) base64_decode((string) $GLOBALS['DOLICLI_FILES']['body'], true);
        } elseif (is_file(Paths::installedBody())) {
            $body = (string) file_get_contents(Paths::installedBody());
        }
        if ('' === $body) {
            throw Failure::operation('NO_BODY', 'No dolicli body for the job: send it in the stream or install dolicli.');
        }

        return (new Jobs($ctx->dataRoot()))->start((string) $ctx->value('id'), $arguments, $body);
    }
}
