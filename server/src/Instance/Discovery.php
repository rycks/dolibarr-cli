<?php

namespace Dolicli\Instance;

use Dolicli\System\Log;
use Dolicli\System\Posix;

/**
 * Find the Dolibarr installations under a directory: htdocs trees (master.inc.php and
 * filefunc.inc.php side by side), with the conf.php they read. Bounded depth and duration,
 * symbolic links not followed, documents/ and dependency directories skipped. conf.php is
 * parsed, never executed, and no database credential is returned.
 */
final class Discovery
{
    const SKIPPED = ['documents', 'node_modules', 'vendor', '.git', 'includes', 'custom', 'theme', 'langs', 'cache', 'tmp', 'proc', 'sys'];

    /** @var int */
    private $maxDepth;

    /** @var float */
    private $deadline;

    /** @var bool */
    private $truncated = false;

    public function __construct(int $maxDepth = 6, int $seconds = 30)
    {
        $this->maxDepth = $maxDepth;
        $this->deadline = microtime(true) + $seconds;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scan(string $root): array
    {
        $found = [];
        $this->walk(rtrim($root, '/'), 0, $found);

        return $found;
    }

    /**
     * @param array<int, array<string, mixed>> $found
     */
    private function walk(string $dir, int $depth, array &$found)
    {
        if (microtime(true) > $this->deadline) {
            $this->truncated = true;

            return;
        }
        if (is_file($dir . '/master.inc.php') && is_file($dir . '/filefunc.inc.php')) {
            $found[] = self::describe($dir);

            return;
        }
        if ($depth >= $this->maxDepth) {
            return;
        }
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || in_array($entry, self::SKIPPED, true)) {
                continue;
            }
            $path = ('/' === $dir ? '' : $dir) . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                continue;
            }
            $this->walk($path, $depth + 1, $found);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(string $htdocs): array
    {
        $instance = new Instance(Instance::idFromHtdocs($htdocs), $htdocs, 'discovery');
        $confFile = $instance->confFile();
        $reader = PhpFileReader::fromFile($confFile);
        $documents = '';
        $url = '';
        $dbType = '';
        if (null !== $reader) {
            $dataRoot = $reader->variable('dolibarr_main_data_root');
            $documents = is_string($dataRoot) ? $dataRoot : '';
            $urlRoot = $reader->variable('dolibarr_main_url_root');
            $url = is_string($urlRoot) ? $urlRoot : '';
            $type = $reader->variable('dolibarr_main_db_type');
            $dbType = is_string($type) ? $type : '';
        } else {
            Log::warning("discovery: cannot read {$confFile}");
        }
        $realDocuments = '' === $documents ? false : realpath($documents);

        return [
            'id' => $instance->id(),
            'htdocs' => $htdocs,
            'conf_file' => $confFile,
            'conf_readable' => null !== $reader,
            'documents' => false === $realDocuments ? ('' === $documents ? null : $documents) : $realDocuments,
            'documents_owner' => false === $realDocuments ? null : Posix::ownerName($realDocuments),
            'documents_group' => false === $realDocuments ? null : Posix::groupOwnerName($realDocuments),
            'version' => $instance->filesVersion(),
            'url' => '' === $url ? null : $url,
            'database_type' => '' === $dbType ? null : $dbType,
        ];
    }
}
