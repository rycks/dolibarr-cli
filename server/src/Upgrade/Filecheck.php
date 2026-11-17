<?php

namespace Dolicli\Upgrade;

use Dolicli\Cli\Failure;
use Dolicli\System\Http;
use Dolicli\System\Log;

/**
 * Integrity of a deployment: MD5 of every file of htdocs against a signature
 * (htdocs/install/filelist-<version>.xml, a given file, or the official one of dolibarr.org).
 */
final class Filecheck
{
    /**
     * @param string $xmlFile Signature file, empty for the local one
     * @return array{source: string, insignature: array<int, string>, missing: array<int, mixed>, updated: array<int, mixed>, ok: array<int, mixed>, added: array<int, mixed>}
     */
    public static function run(bool $remote, string $xmlFile, bool $detectExtra): array
    {
        global $conffile;

        list($source, $content) = self::signature($remote, $xmlFile);
        $root = FileSignature::parse($content);
        if (null === $root) {
            Log::error('invalid signature ' . $source);
            throw Failure::operation('SIGNATURE_INVALID', 'Invalid XML signature (no dolibarr_htdocs_dir node): ' . $source);
        }
        // Debian/RPM packages patch filefunc.inc.php: Dolibarr ignores it there, so do we.
        $isPackaged = isset($conffile) && '/etc/dolibarr/conf.php' === $conffile;
        $list = FileSignature::compare($root, DOL_DOCUMENT_ROOT, $isPackaged);
        if ($detectExtra) {
            $list['added'] = FileSignature::extraFiles($root, DOL_DOCUMENT_ROOT, $list['insignature']);
        }
        $list['source'] = $source;

        return $list;
    }

    /**
     * Path of the signature shipped with the files.
     */
    public static function localSignature(): string
    {
        return DOL_DOCUMENT_ROOT . '/install/filelist-' . DOL_VERSION . '.xml';
    }

    /**
     * @return array{0: string, 1: string} Source and content
     */
    private static function signature(bool $remote, string $xmlFile): array
    {
        if ($remote) {
            $source = sprintf(FileSignature::REMOTE_URL, DOL_VERSION);
            $content = Http::get($source, 60);
            if (null === $content) {
                throw Failure::operation('SIGNATURE_NOT_FOUND', 'Cannot download the official signature: ' . Http::lastError());
            }

            return [$source, $content];
        }
        $source = '' !== $xmlFile ? $xmlFile : self::localSignature();
        if (!is_file($source)) {
            Log::error('signature not found ' . $source);
            throw Failure::operation(
                'SIGNATURE_NOT_FOUND',
                'Reference XML signature not found: ' . $source . ('' === $xmlFile ? ' (use --remote for the official signature, or --xmlfile=PATH)' : '')
            );
        }
        $content = @file_get_contents($source);
        if (!is_string($content)) {
            throw Failure::operation('SIGNATURE_NOT_FOUND', 'Cannot read ' . $source);
        }

        return [$source, $content];
    }
}
