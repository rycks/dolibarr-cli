<?php

declare(strict_types=1);

namespace App\Command\System;

use Minicli\Command\CommandController;

class FilecheckController extends CommandController
{
    private const EXIT_OK = 0;
    private const EXIT_MODIFIED = 1;
    private const EXIT_MISSING = 2;
    private const EXIT_NO_XML = 3;

    private int $exitCode = self::EXIT_OK;

    public function usage(): void
    {
        $this->info("Dolibarr deployment integrity check

Usage:
  ./dolibarr system filecheck
  ./dolibarr system filecheck --xmlfile=/path/to/filelist-X.Y.Z.xml
  ./dolibarr system filecheck --show-ok
  ./dolibarr system filecheck --detect-extra
  ./dolibarr system filecheck --format=json

Description:
  Verifies the integrity of a Dolibarr deployment by comparing the MD5
  checksum of every file in htdocs/ with the reference signature shipped
  in htdocs/install/filelist-<version>.xml.

Options:
  --xmlfile=PATH   Use a specific XML signature file (default: auto-detect
                   from current DOL_VERSION in htdocs/install/)
  --show-ok        Also display files that match (verbose)
  --detect-extra   Report files present on disk but not in the signature
                   (slower: scans the whole htdocs tree)
  --format=text    Human-readable table output (default)
  --format=json    Machine-readable JSON output
  --help           Display this help message

Exit codes:
  0  All files match the signature
  1  Some files are modified
  2  Some files are missing
  3  Reference XML signature not found

Examples:
  ./dolibarr system filecheck
  ./dolibarr system filecheck --detect-extra --show-ok
  ./dolibarr system filecheck --format=json > report.json");
    }

    public function handle(): void
    {
        if ($this->hasFlag('help')) {
            $this->usage();
            return;
        }

        if (!$this->validateDolibarrEnvironment()) {
            $this->setExit(self::EXIT_NO_XML);
            return;
        }

        $xmlfile = $this->resolveXmlFile();
        if ($xmlfile === null) {
            $this->setExit(self::EXIT_NO_XML);
            return;
        }

        $xml = simplexml_load_file($xmlfile);
        if ($xml === false) {
            $this->error("Failed to parse XML signature: " . $xmlfile);
            $this->setExit(self::EXIT_NO_XML);
            return;
        }

        if (!isset($xml->dolibarr_htdocs_dir[0])) {
            $this->error("XML signature does not contain dolibarr_htdocs_dir node: " . $xmlfile);
            $this->setExit(self::EXIT_NO_XML);
            return;
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

        $fileList = ['insignature' => [], 'missing' => [], 'updated' => [], 'added' => [], 'ok' => []];
        $checksumconcat = [];

        $this->scanFiles($fileList, $xml->dolibarr_htdocs_dir[0], '', DOL_DOCUMENT_ROOT, $checksumconcat);

        if ($this->hasFlag('detect-extra')) {
            $this->detectExtraFiles($fileList, $xml->dolibarr_htdocs_dir[0]);
        }

        $format = $this->getParam('format') ?? 'text';

        if ($format === 'json') {
            $this->renderJson($fileList, $xmlfile);
        } else {
            $this->renderText($fileList, $xmlfile);
        }

        if (!empty($fileList['missing'])) {
            $this->setExit(self::EXIT_MISSING);
        } elseif (!empty($fileList['updated'])) {
            $this->setExit(self::EXIT_MODIFIED);
        }
    }

    private function validateDolibarrEnvironment(): bool
    {
        if (!defined('DOL_DOCUMENT_ROOT')) {
            $this->error("DOL_DOCUMENT_ROOT is not defined!");
            $this->info("Please ensure Dolibarr is properly initialized (DOLPATH env var).");
            return false;
        }
        if (!defined('DOL_VERSION')) {
            $this->error("DOL_VERSION is not defined!");
            return false;
        }
        return true;
    }

    private function resolveXmlFile(): ?string
    {
        $custom = $this->getParam('xmlfile');
        if ($custom !== null && $custom !== '') {
            if (!is_file($custom)) {
                $this->error("XML signature file not found: " . $custom);
                return null;
            }
            return $custom;
        }

        $candidate = DOL_DOCUMENT_ROOT . '/install/filelist-' . DOL_VERSION . '.xml';
        if (is_file($candidate)) {
            return $candidate;
        }

        $this->error("Reference XML signature not found: " . $candidate);
        $this->info("Use --xmlfile=PATH to point to a specific signature file.");
        return null;
    }

    /**
     * Recursive scan that mirrors the logic of Dolibarr's getFilesUpdated()
     * but also collects 'ok' entries when --show-ok is requested.
     *
     * @param array<string,array<int,mixed>> $fileList
     */
    private function scanFiles(array &$fileList, \SimpleXMLElement $dir, string $path, string $pathref, array &$checksumconcat): void
    {
        global $conffile;

        foreach ($dir->md5file as $file) {
            $filename = $path . (string) $file['name'];
            $expectedmd5 = (string) $file;
            $expectedsize = (string) ($file['size'] ?? '');

            $fileList['insignature'][] = $filename;

            $fullpath = $pathref . '/' . $filename;
            if (!file_exists($fullpath)) {
                $fileList['missing'][] = [
                    'filename' => $filename,
                    'expectedmd5' => $expectedmd5,
                    'expectedsize' => $expectedsize,
                ];
                continue;
            }

            $md5local = md5_file($fullpath);

            // Same exception as Dolibarr: ignore filefunc.inc.php on deb/rpm installs
            if (isset($conffile) && $conffile === '/etc/dolibarr/conf.php' && $filename === '/filefunc.inc.php') {
                $checksumconcat[] = $expectedmd5;
                continue;
            }

            if ($md5local !== $expectedmd5) {
                $fileList['updated'][] = [
                    'filename' => $filename,
                    'expectedmd5' => $expectedmd5,
                    'md5' => (string) $md5local,
                    'expectedsize' => $expectedsize,
                ];
            } else {
                $fileList['ok'][] = ['filename' => $filename, 'md5' => (string) $md5local];
            }
            $checksumconcat[] = $md5local;
        }

        foreach ($dir->dir as $subdir) {
            $this->scanFiles($fileList, $subdir, $path . (string) $subdir['name'] . '/', $pathref, $checksumconcat);
        }
    }

    private function detectExtraFiles(array &$fileList, \SimpleXMLElement $rootDir): void
    {
        $includecustom = (int) ($rootDir['includecustom'] ?? 0);

        $regextoinclude = '\.(php|php3|php4|php5|phtml|phps|phar|inc|css|scss|html|xml|js|json|tpl|jpg|jpeg|png|gif|ico|sql|lang|txt|yml|bak|md|mp3|mp4|wav|mkv|z|gz|zip|rar|tar|less|svg|eot|woff|woff2|ttf|manifest)$';
        $regextoexclude = '(' . ($includecustom ? '' : 'custom|') . 'documents|conf|install|dejavu-fonts-ttf-.*|public\/test|sabre\/sabre\/.*\/tests|Shared\/PCLZip|nusoap\/lib\/Mail|php\/example|php\/test|geoip\/sample.*\.php|ckeditor\/samples|ckeditor\/adapters)$';

        $scanfiles = dol_dir_list(DOL_DOCUMENT_ROOT, 'files', 1, $regextoinclude, $regextoexclude);
        $insignature = array_flip($fileList['insignature']);

        foreach ($scanfiles as $file) {
            $relative = preg_replace('/^' . preg_quote(DOL_DOCUMENT_ROOT, '/') . '/', '', $file['fullname']);
            if (!isset($insignature[$relative])) {
                $fileList['added'][] = [
                    'filename' => $relative,
                    'md5' => (string) @md5_file($file['fullname']),
                ];
            }
        }
    }

    private function renderText(array $fileList, string $xmlfile): void
    {
        $this->info("Dolibarr file integrity check");
        $this->display("Version:   " . DOL_VERSION);
        $this->display("Document root: " . DOL_DOCUMENT_ROOT);
        $this->display("Signature: " . $xmlfile);
        $this->display("");

        $missing = $fileList['missing'];
        $updated = $fileList['updated'];
        $added = $fileList['added'];
        $ok = $fileList['ok'];

        if (!empty($missing)) {
            $this->error("Missing files: " . count($missing));
            foreach ($missing as $f) {
                $this->display("  [MISSING ] " . $f['filename'] . "  (expected md5=" . $f['expectedmd5'] . ")");
            }
            $this->display("");
        }

        if (!empty($updated)) {
            $this->error("Modified files: " . count($updated));
            foreach ($updated as $f) {
                $this->display("  [MODIFIED] " . $f['filename']);
                $this->display("             expected " . $f['expectedmd5'] . "  got " . $f['md5']);
            }
            $this->display("");
        }

        if (!empty($added)) {
            $this->info("Extra files (not in signature): " . count($added));
            foreach ($added as $f) {
                $this->display("  [EXTRA   ] " . $f['filename'] . "  (md5=" . $f['md5'] . ")");
            }
            $this->display("");
        }

        if ($this->hasFlag('show-ok') && !empty($ok)) {
            $this->display("OK files: " . count($ok));
            foreach ($ok as $f) {
                $this->display("  [OK      ] " . $f['filename']);
            }
            $this->display("");
        }

        $this->info("Summary:");
        $this->display("  In signature: " . count($fileList['insignature']));
        $this->display("  OK:           " . count($ok));
        $this->display("  Modified:     " . count($updated));
        $this->display("  Missing:      " . count($missing));
        if ($this->hasFlag('detect-extra')) {
            $this->display("  Extra:        " . count($added));
        }

        if (empty($missing) && empty($updated)) {
            $this->success("Deployment integrity OK.");
        } else {
            $this->error("Deployment integrity FAILED.");
        }
    }

    private function renderJson(array $fileList, string $xmlfile): void
    {
        $payload = [
            'version' => DOL_VERSION,
            'document_root' => DOL_DOCUMENT_ROOT,
            'signature' => $xmlfile,
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

        $this->display((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function setExit(int $code): void
    {
        $this->exitCode = $code;
        if (PHP_SAPI === 'cli' && $code !== self::EXIT_OK) {
            // Defer the exit so that all output is flushed first.
            register_shutdown_function(static function () use ($code): void {
                exit($code);
            });
        }
    }

    public function required(): array
    {
        return [];
    }
}
