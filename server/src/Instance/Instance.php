<?php

namespace Dolicli\Instance;

/**
 * A Dolibarr installation (an htdocs and its conf.php) and its dolicli settings.
 */
final class Instance
{
    const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    /** Reserved identifiers (targets of the pilot). */
    const RESERVED = ['all'];

    /** @var string */
    private $id;

    /** @var string */
    private $htdocs;

    /** @var string */
    private $source;

    /** @var array<string, string> */
    private $settings;

    /**
     * @param array<string, string> $settings Keys of instances.ini (run_as, php, web_php...)
     */
    public function __construct(string $id, string $htdocs, string $source, array $settings = [])
    {
        $this->id = $id;
        $this->htdocs = rtrim($htdocs, '/');
        $this->source = $source;
        $this->settings = $settings;
    }

    public static function isValidId(string $id): bool
    {
        return 1 === preg_match(self::ID_PATTERN, $id) && !in_array($id, self::RESERVED, true);
    }

    /**
     * Identifier deduced from an htdocs path: name of its parent directory.
     */
    public static function idFromHtdocs(string $htdocs): string
    {
        $name = strtolower(basename(dirname(rtrim($htdocs, '/'))));
        $name = (string) preg_replace('/[^a-z0-9_-]+/', '-', $name);
        $name = ltrim($name, '_-');

        return '' === $name || !self::isValidId($name) ? 'dolibarr' : $name;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function htdocs(): string
    {
        return $this->htdocs;
    }

    /**
     * instances.ini, option, environment or default.
     */
    public function source(): string
    {
        return $this->source;
    }

    public function setting(string $name, string $default = ''): string
    {
        return isset($this->settings[$name]) && '' !== $this->settings[$name] ? $this->settings[$name] : $default;
    }

    /**
     * @return array<string, string>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * Path of conf.php: htdocs/conf/conf.php, or the file named by $conffile in
     * filefunc.inc.php (Debian and RPM packages read /etc/dolibarr/conf.php).
     */
    public function confFile(): string
    {
        $reader = PhpFileReader::fromFile($this->htdocs . '/filefunc.inc.php');
        $conffile = null === $reader ? null : $reader->variable('conffile');
        if (!is_string($conffile) || '' === $conffile) {
            return $this->htdocs . '/conf/conf.php';
        }

        return '/' === $conffile[0] ? $conffile : $this->htdocs . '/' . $conffile;
    }

    /**
     * documents/ directory (dolibarr_main_data_root of conf.php, read without executing it),
     * empty string when it cannot be read.
     */
    public function dataRoot(): string
    {
        $reader = PhpFileReader::fromFile($this->confFile());
        $dataRoot = null === $reader ? null : $reader->variable('dolibarr_main_data_root');
        if (!is_string($dataRoot) || '' === $dataRoot) {
            return '';
        }
        $real = realpath($dataRoot);

        return false === $real ? rtrim($dataRoot, '/') : $real;
    }

    /**
     * Version of the files, read in filefunc.inc.php without loading Dolibarr.
     */
    public function filesVersion(): string
    {
        $reader = PhpFileReader::fromFile($this->htdocs . '/filefunc.inc.php');
        $version = null === $reader ? null : $reader->constant('DOL_VERSION');

        return is_string($version) ? $version : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'htdocs' => $this->htdocs];
    }
}
