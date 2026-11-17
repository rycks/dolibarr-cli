<?php

namespace Dolicli\Upgrade;

use Dolicli\Dolibarr\Dolibarr;
use SimpleXMLElement;

/**
 * Compare a Dolibarr tree with a filelist-x.y.z.xml signature (MD5 of every file),
 * with the same rules as Dolibarr's getFilesUpdated().
 */
final class FileSignature
{
    const REMOTE_URL = 'https://www.dolibarr.org/files/stable/signatures/filelist-%s.xml';

    /**
     * Root node <dolibarr_htdocs_dir> of a signature, null when the XML is invalid.
     *
     * @return SimpleXMLElement|null
     */
    public static function parse(string $xmlContent)
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (false === $xml || ! isset($xml->dolibarr_htdocs_dir[0])) {
            return null;
        }

        return $xml->dolibarr_htdocs_dir[0];
    }

    /**
     * @return array{
     *     insignature: array<int, string>,
     *     missing: array<int, array{filename: string, expectedmd5: string, expectedsize: string}>,
     *     updated: array<int, array{filename: string, expectedmd5: string, md5: string, expectedsize: string}>,
     *     ok: array<int, array{filename: string, md5: string}>,
     *     added: array<int, array{filename: string, md5: string}>
     * }
     */
    public static function compare(SimpleXMLElement $root, string $basePath, bool $ignoreDebianFilefunc = false): array
    {
        $result = ['insignature' => [], 'missing' => [], 'updated' => [], 'ok' => [], 'added' => []];
        self::scan($result, $root, '', rtrim($basePath, '/'), $ignoreDebianFilefunc);

        return $result;
    }

    /**
     * Files on disk that are not in the signature (same include/exclude rules as Dolibarr).
     * Needs Dolibarr's dol_dir_list().
     *
     * @param  array<int, string>  $inSignature
     * @return array<int, array{filename: string, md5: string}>
     */
    public static function extraFiles(SimpleXMLElement $root, string $basePath, array $inSignature): array
    {
        Dolibarr::requireCore('core/lib/files.lib.php');

        $basePath = rtrim($basePath, '/');
        $includecustom = (int) $root['includecustom'];
        // Same include/exclude rules as Dolibarr's getFilesUpdated().
        $regextoinclude = '\.(php|php3|php4|php5|phtml|phps|phar|inc|css|scss|html|xml|js|json|tpl|jpg|jpeg|png|gif|ico|sql|lang|txt|yml|bak|md'
            . '|mp3|mp4|wav|mkv|z|gz|zip|rar|tar|less|svg|eot|woff|woff2|ttf|manifest)$';
        $regextoexclude = '(' . ($includecustom ? '' : 'custom|') . 'documents|conf|install|dejavu-fonts-ttf-.*|public\/test|sabre\/sabre\/.*\/tests'
            . '|Shared\/PCLZip|nusoap\/lib\/Mail|php\/example|php\/test|geoip\/sample.*\.php|ckeditor\/samples|ckeditor\/adapters)$';

        $known = array_flip($inSignature);
        $added = [];
        foreach (dol_dir_list($basePath, 'files', 1, $regextoinclude, [$regextoexclude]) as $file) {
            $relative = substr($file['fullname'], strlen($basePath));
            if (! isset($known[$relative])) {
                $md5 = md5_file($file['fullname']);
                $added[] = ['filename' => $relative, 'md5' => false === $md5 ? '' : $md5];
            }
        }

        return $added;
    }

    /**
     * @param  array<string, array<int, mixed>>  $result
     */
    private static function scan(array &$result, SimpleXMLElement $dir, string $path, string $basePath, bool $ignoreDebianFilefunc)
    {
        foreach ($dir->md5file as $file) {
            $filename = $path . $file['name'];
            $expectedmd5 = (string) $file;
            $expectedsize = (string) $file['size'];

            $result['insignature'][] = $filename;

            $fullpath = $basePath . '/' . $filename;
            if (! file_exists($fullpath)) {
                $result['missing'][] = ['filename' => $filename, 'expectedmd5' => $expectedmd5, 'expectedsize' => $expectedsize];

                continue;
            }

            // Same exception as Dolibarr: filefunc.inc.php is patched by deb/rpm packages
            if ($ignoreDebianFilefunc && '/filefunc.inc.php' === $filename) {
                continue;
            }

            $md5local = (string) md5_file($fullpath);
            if ($md5local !== $expectedmd5) {
                $result['updated'][] = ['filename' => $filename, 'expectedmd5' => $expectedmd5, 'md5' => $md5local, 'expectedsize' => $expectedsize];
            } else {
                $result['ok'][] = ['filename' => $filename, 'md5' => $md5local];
            }
        }

        foreach ($dir->dir as $subdir) {
            self::scan($result, $subdir, $path . $subdir['name'] . '/', $basePath, $ignoreDebianFilefunc);
        }
    }
}
