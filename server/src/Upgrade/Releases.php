<?php

namespace Dolicli\Upgrade;

use Dolicli\Cli\Failure;
use Dolicli\System\Http;

/**
 * Versions published on dolibarr.org.
 */
final class Releases
{
    const VERSIONS_URL = 'https://www.dolibarr.org/files/stable/standard/';

    const DOWNLOAD_URL = 'https://www.dolibarr.org/files/stable/standard/dolibarr-%s.%s';

    /**
     * Published versions, sorted ascending. Failure when dolibarr.org cannot be reached.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $html = Http::get(self::VERSIONS_URL);
        if (null === $html) {
            throw Failure::operation('RELEASES_UNAVAILABLE', 'Could not fetch the versions from dolibarr.org: ' . Http::lastError());
        }

        return self::parse($html);
    }

    /**
     * @return array<int, string>
     */
    public static function parse(string $html): array
    {
        preg_match_all('/dolibarr-(\d+\.\d+\.\d+)\.(?:tgz|zip)/', $html, $matches);
        $versions = array_values(array_unique($matches[1]));
        usort($versions, 'version_compare');

        return $versions;
    }

    /**
     * Latest version of the branch of $current (same x.y), empty string when none.
     *
     * @param array<int, string> $versions
     */
    public static function latestMinor(string $current, array $versions): string
    {
        $found = '';
        foreach ($versions as $version) {
            if (MigrationPlanner::sameBranch($version, $current)) {
                $found = $version;
            }
        }

        return $found;
    }

    /**
     * @param array<int, string> $versions
     */
    public static function latest(array $versions): string
    {
        return [] === $versions ? '' : (string) end($versions);
    }

    /**
     * @return array<int, string> URLs of the archive, tgz first
     */
    public static function archiveUrls(string $version): array
    {
        return [sprintf(self::DOWNLOAD_URL, $version, 'tgz'), sprintf(self::DOWNLOAD_URL, $version, 'zip')];
    }
}
