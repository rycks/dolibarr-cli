<?php

namespace Dolicli\Dolibarr;

use Dolicli\Cli\Failure;
use Dolicli\Instance\Instance;
use Dolicli\System\Log;

/**
 * Preparation of the inclusion of master.inc.php.
 *
 * master.inc.php creates $conf, $db, $langs as global variables: it is included by
 * the global code of the body (main.php), never from a method. Before the inclusion:
 * chdir() into htdocs, display_errors on stderr, an output buffer. After it, the buffer
 * is thrown away: on a database connection failure, master.inc.php prints the host,
 * port, user and database name, then exits. That output goes to the log, never to stdout.
 */
final class Loader
{
    /** @var int */
    private $bufferLevel = -1;

    /**
     * @return string Path of master.inc.php to include
     */
    public function prepare(Instance $instance, int $entity): string
    {
        if (!defined('NOSESSION')) {
            define('NOSESSION', '1');
        }
        if (!defined('NOREQUIREMENU')) {
            define('NOREQUIREMENU', '1');
        }
        if (!defined('NOREQUIREHTML')) {
            define('NOREQUIREHTML', '1');
        }
        if ($entity > 1 && !defined('DOLENTITY')) {
            define('DOLENTITY', $entity);
        }
        ini_set('display_errors', 'stderr');
        if (!@chdir($instance->htdocs())) {
            Log::error('cannot chdir to ' . $instance->htdocs());
            throw Failure::environment('DOLIBARR_UNREADABLE', "Cannot enter {$instance->htdocs()}.");
        }
        ob_start();
        $this->bufferLevel = ob_get_level();

        return $instance->htdocs() . '/master.inc.php';
    }

    /**
     * Discard what Dolibarr printed while loading, log it.
     */
    public function discardOutput(): string
    {
        $output = '';
        while ($this->bufferLevel > 0 && ob_get_level() >= $this->bufferLevel) {
            $output = (string) ob_get_clean() . $output;
        }
        $this->bufferLevel = -1;
        $output = trim(html_entity_decode(strip_tags($output), ENT_QUOTES));
        if ('' !== $output) {
            Log::warning('output of master.inc.php: ' . substr($output, 0, 2000));
        }

        return $output;
    }

    /**
     * Check what master.inc.php left behind: a connected database handler.
     */
    public static function checkLoaded()
    {
        global $db, $conf;

        if (!defined('DOL_DOCUMENT_ROOT') || !isset($conf) || !is_object($conf)) {
            Log::error('master.inc.php did not define the Dolibarr environment');
            throw Failure::environment('DOLIBARR_UNREADABLE', 'Dolibarr did not load (conf.php unreadable or invalid).');
        }
        if (!isset($db) || !is_object($db) || empty($db->connected)) {
            $reason = isset($db) && is_object($db) && !empty($db->error) ? (string) $db->error : 'not connected';
            Log::error('database unreachable: ' . $reason);
            throw Failure::environment('DATABASE_UNREACHABLE', 'Dolibarr database unreachable (details in the system log).');
        }
    }
}
