<?php

namespace Dolicli\Command\Cron;

use Dolicli\Cli\Command;
use Dolicli\Cli\Context;
use Dolicli\Cli\Failure;
use Dolicli\Cli\Param;
use Dolicli\Cli\Text;
use Dolicli\Dolibarr\CronJobs;
use Dolicli\Dolibarr\Dolibarr;

final class ListCommand extends Command
{
    /** Longest last_output returned (characters). */
    const OUTPUT_LENGTH = 200;

    public function name(): string
    {
        return 'cron list';
    }

    public function summary(): string
    {
        return 'List the scheduled jobs and their last run';
    }

    public function params(): array
    {
        return [
            Param::flag('failed', 'Only active jobs whose last run failed'),
            Param::flag('late', 'Only active jobs late by more than ' . CronJobs::STALE_MARGIN . ' s'),
            Param::flag('stuck', 'Only jobs running for more than ' . CronJobs::STUCK_AFTER . ' s'),
        ];
    }

    public function help(): string
    {
        return "Flags combine with OR; without flag every job is listed. Failed, late and stuck are the definitions\n"
            . "of the monitor checks cron.failed, cron.stale and cron.stuck. The totals failed, late and stuck count\n"
            . "every job of the instance, whatever the flags. Jobs of the current entity and of entity 0 are listed\n"
            . '(--entity in multicompany), problems first (stuck, failed, late), then by label.';
    }

    public function examples(): array
    {
        return ['cron list --failed --stuck'];
    }

    public function execute(Context $ctx): array
    {
        $db = Dolibarr::db();
        $now = time();
        $sql = 'SELECT rowid, label, status, frequency, unitfrequency, datelastrun, datenextrun, lastresult, lastoutput, processing,'
            . ' CASE WHEN ' . CronJobs::failedCondition() . ' THEN 1 ELSE 0 END AS is_failed,'
            . ' CASE WHEN ' . CronJobs::lateCondition($now) . ' THEN 1 ELSE 0 END AS is_late,'
            . ' CASE WHEN ' . CronJobs::stuckCondition($now) . ' THEN 1 ELSE 0 END AS is_stuck'
            . ' FROM ' . MAIN_DB_PREFIX . 'cronjob WHERE ' . CronJobs::entityCondition();
        $resql = $db->query($sql);
        if (!$resql) {
            throw Failure::operation('CRON_LIST_FAILED', "Can't fetch scheduled jobs: " . $db->lasterror());
        }

        $wanted = [];
        foreach (['failed', 'late', 'stuck'] as $flag) {
            if ($ctx->flag($flag)) {
                $wanted[] = $flag;
            }
        }
        $totals = ['failed' => 0, 'late' => 0, 'stuck' => 0];
        $jobs = [];
        while ($obj = $db->fetch_object($resql)) {
            $job = $this->describe($obj);
            $selected = [] === $wanted;
            foreach ($totals as $flag => $total) {
                if ($job[$flag]) {
                    $totals[$flag] = $total + 1;
                    $selected = $selected || in_array($flag, $wanted, true);
                }
            }
            if ($selected) {
                $jobs[] = $job;
            }
        }
        usort($jobs, [self::class, 'compare']);

        return ['jobs' => $jobs, 'count' => count($jobs), 'failed' => $totals['failed'], 'late' => $totals['late'], 'stuck' => $totals['stuck']];
    }

    public function renderText(array $data): string
    {
        if ([] === $data['jobs']) {
            return "No scheduled job.\n";
        }
        $rows = [];
        foreach ($data['jobs'] as $job) {
            $flags = [];
            foreach (['stuck', 'failed', 'late'] as $flag) {
                if ($job[$flag]) {
                    $flags[] = $flag;
                }
            }
            if ($job['processing'] && !$job['stuck']) {
                $flags[] = 'running';
            }
            $rows[] = [
                $job['id'],
                $job['label'],
                $job['status'],
                $job['frequency'],
                self::shortDate($job['last_run']),
                null === $job['last_result'] ? '' : $job['last_result'],
                self::shortDate($job['next_run']),
                implode(', ', $flags),
            ];
        }

        return Text::table(['Id', 'Label', 'Status', 'Frequency', 'Last run', 'Result', 'Next run', 'Flags'], $rows)
            . sprintf("\nListed: %d. In the instance: %d failed, %d late, %d stuck.\n", $data['count'], $data['failed'], $data['late'], $data['stuck']);
    }

    /**
     * @param \stdClass $obj Row of the cronjob table
     * @return array<string, mixed>
     */
    private function describe($obj): array
    {
        $status = (int) $obj->status;
        $result = null === $obj->lastresult ? '' : (string) $obj->lastresult;

        return [
            'id' => (int) $obj->rowid,
            'label' => (string) $obj->label,
            'status' => 1 === $status ? 'enabled' : (2 === $status ? 'archived' : 'disabled'),
            'frequency' => CronJobs::describeFrequency($obj->frequency, $obj->unitfrequency),
            'last_run' => self::isoDate($obj->datelastrun),
            'last_result' => '' === $result ? null : ('0' === $result ? 'ok' : 'failed'),
            'last_output' => self::firstLine(null === $obj->lastoutput ? '' : (string) $obj->lastoutput),
            'next_run' => self::isoDate($obj->datenextrun),
            'processing' => 1 === (int) $obj->processing,
            'failed' => 1 === (int) $obj->is_failed,
            'late' => 1 === (int) $obj->is_late,
            'stuck' => 1 === (int) $obj->is_stuck,
        ];
    }

    /**
     * Date of the database (server time zone, as written by Dolibarr) in ISO 8601.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function isoDate($value)
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $timestamp = Dolibarr::db()->jdate($value);

        return is_numeric($timestamp) ? date('c', (int) $timestamp) : null;
    }

    private static function firstLine(string $output): string
    {
        foreach (preg_split('/\R/u', Text::safe($output)) ?: [] as $line) {
            $line = trim($line);
            if ('' !== $line) {
                return preg_match('/^.{0,' . self::OUTPUT_LENGTH . '}/us', $line, $match) ? $match[0] : '';
            }
        }

        return '';
    }

    /**
     * @param string|null $iso
     */
    private static function shortDate($iso): string
    {
        return null === $iso ? '' : str_replace('T', ' ', substr($iso, 0, 16));
    }

    /**
     * Stuck, failed, late first, then by label.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function compare(array $a, array $b): int
    {
        foreach (['stuck', 'failed', 'late'] as $flag) {
            if ($a[$flag] !== $b[$flag]) {
                return $a[$flag] ? -1 : 1;
            }
        }
        $byLabel = strcasecmp($a['label'], $b['label']);

        return 0 !== $byLabel ? $byLabel : $a['id'] - $b['id'];
    }
}
