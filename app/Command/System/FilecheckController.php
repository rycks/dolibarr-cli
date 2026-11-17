<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Support\Command;
use App\Util\FileSignature;
use App\Util\Http;

class FilecheckController extends Command
{
    private const EXIT_MODIFIED = 1;

    private const EXIT_MISSING = 2;

    private const EXIT_NO_XML = 3;

    public function summary(): string
    {
        return 'Check deployed files against the official signature';
    }

    public function usage(): void
    {
        $this->info('Dolibarr deployment integrity check

Usage:
  ./dolibarr system filecheck
  ./dolibarr system filecheck --remote
  ./dolibarr system filecheck --xmlfile=/path/to/filelist-X.Y.Z.xml
  ./dolibarr system filecheck --show-ok
  ./dolibarr system filecheck --detect-extra
  ./dolibarr system filecheck --format=json

Description:
  Verifies the integrity of a Dolibarr deployment by comparing the MD5
  checksum of every file in htdocs/ with a reference signature: the one
  shipped in htdocs/install/filelist-<version>.xml, or the official one
  published on dolibarr.org (--remote).

Options:
  --remote         Use the official signature from dolibarr.org (recommended:
                   the local one can be altered along with the files)
  --xmlfile=PATH   Use a specific XML signature file
  --show-ok        Also display files that match (verbose)
  --detect-extra   Report files present on disk but not in the signature
                   (slower: scans the whole htdocs tree)
  --format=text    Human-readable output (default)
  --format=json    Machine-readable JSON output
  --help           Display this help message

Exit codes:
  0  All files match the signature
  1  Some files are modified
  2  Some files are missing
  3  Reference XML signature not found

Examples:
  ./dolibarr system filecheck --remote
  ./dolibarr system filecheck --detect-extra --show-ok
  ./dolibarr system filecheck --format=json > report.json');
    }

    public function handle(): void
    {
        global $conffile;

        if ($this->hasFlag('help')) {
            $this->usage();

            return;
        }

        if ( ! defined('DOL_DOCUMENT_ROOT') || ! defined('DOL_VERSION')) {
            $this->fail('Dolibarr is not loaded: check the DOLPATH environment variable.', self::EXIT_NO_XML);

            return;
        }

        $source = '';
        $xmlContent = $this->loadSignature($source);
        if (null === $xmlContent) {
            return;
        }

        $root = FileSignature::parse($xmlContent);
        if (null === $root) {
            $this->fail('Invalid XML signature (no dolibarr_htdocs_dir node): '.$source, self::EXIT_NO_XML);

            return;
        }

        // Debian/RPM packages patch filefunc.inc.php: Dolibarr ignores it there, so do we.
        $isPackaged = isset($conffile) && '/etc/dolibarr/conf.php' === $conffile;
        $fileList = FileSignature::compare($root, DOL_DOCUMENT_ROOT, $isPackaged);
        if ($this->hasFlag('detect-extra')) {
            $fileList['added'] = FileSignature::extraFiles($root, DOL_DOCUMENT_ROOT, $fileList['insignature']);
        }

        if ('json' === $this->param('format')) {
            $this->renderJson($fileList, $source);
        } else {
            $this->renderText($fileList, $source);
        }

        if ([] !== $fileList['missing']) {
            $this->failSilently('filecheck: '.count($fileList['missing']).' file(s) missing', self::EXIT_MISSING);
        } elseif ([] !== $fileList['updated']) {
            $this->failSilently('filecheck: '.count($fileList['updated']).' file(s) modified', self::EXIT_MODIFIED);
        }
    }

    private function loadSignature(string &$source): ?string
    {
        if ($this->hasFlag('remote')) {
            $source = sprintf(FileSignature::REMOTE_URL, DOL_VERSION);
            $content = Http::get($source, 60);
            if (null === $content) {
                $this->fail('Cannot download the official signature: '.Http::lastError(), self::EXIT_NO_XML);
            }

            return $content;
        }

        $custom = $this->param('xmlfile');
        $source = (null !== $custom && '' !== $custom) ? $custom : DOL_DOCUMENT_ROOT.'/install/filelist-'.DOL_VERSION.'.xml';
        if ( ! is_file($source)) {
            $this->fail('Reference XML signature not found: '.$source, self::EXIT_NO_XML);
            if (null === $custom) {
                $this->info('Use --remote for the official signature, or --xmlfile=PATH.');
            }

            return null;
        }

        $content = file_get_contents($source);
        if (false === $content) {
            $this->fail('Cannot read '.$source, self::EXIT_NO_XML);

            return null;
        }

        return $content;
    }

    /**
     * @param  array<string, array<int, mixed>>  $fileList
     */
    private function renderText(array $fileList, string $source): void
    {
        $this->info('Dolibarr file integrity check');
        $this->display('Version:       '.DOL_VERSION);
        $this->display('Document root: '.DOL_DOCUMENT_ROOT);
        $this->display('Signature:     '.$source);
        $this->display('');

        $missing = $fileList['missing'];
        $updated = $fileList['updated'];
        $added = $fileList['added'];
        $ok = $fileList['ok'];

        if ([] !== $missing) {
            $this->error('Missing files: '.count($missing));
            foreach ($missing as $f) {
                $this->display('  [MISSING ] '.$f['filename'].'  (expected md5='.$f['expectedmd5'].')');
            }
            $this->display('');
        }

        if ([] !== $updated) {
            $this->error('Modified files: '.count($updated));
            foreach ($updated as $f) {
                $this->display('  [MODIFIED] '.$f['filename']);
                $this->display('             expected '.$f['expectedmd5'].'  got '.$f['md5']);
            }
            $this->display('');
        }

        if ([] !== $added) {
            $this->info('Extra files (not in signature): '.count($added));
            foreach ($added as $f) {
                $this->display('  [EXTRA   ] '.$f['filename'].'  (md5='.$f['md5'].')');
            }
            $this->display('');
        }

        if ($this->hasFlag('show-ok') && [] !== $ok) {
            $this->display('OK files: '.count($ok));
            foreach ($ok as $f) {
                $this->display('  [OK      ] '.$f['filename']);
            }
            $this->display('');
        }

        $this->info('Summary:');
        $this->display('  In signature: '.count($fileList['insignature']));
        $this->display('  OK:           '.count($ok));
        $this->display('  Modified:     '.count($updated));
        $this->display('  Missing:      '.count($missing));
        if ($this->hasFlag('detect-extra')) {
            $this->display('  Extra:        '.count($added));
        }

        if ([] === $missing && [] === $updated) {
            $this->success('Deployment integrity OK.');
        } else {
            $this->error('Deployment integrity FAILED.');
        }
    }

    /**
     * @param  array<string, array<int, mixed>>  $fileList
     */
    private function renderJson(array $fileList, string $source): void
    {
        $payload = [
            'version' => DOL_VERSION,
            'document_root' => DOL_DOCUMENT_ROOT,
            'signature' => $source,
            'summary' => [
                'in_signature' => count($fileList['insignature']),
                'ok' => count($fileList['ok']),
                'modified' => count($fileList['updated']),
                'missing' => count($fileList['missing']),
                'extra' => count($fileList['added']),
            ],
            'missing' => $fileList['missing'],
            'modified' => $fileList['updated'],
            'extra' => $fileList['added'],
        ];

        if ($this->hasFlag('show-ok')) {
            $payload['ok'] = $fileList['ok'];
        }

        $this->rawOutput(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
