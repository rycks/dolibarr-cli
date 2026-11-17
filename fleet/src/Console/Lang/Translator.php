<?php

declare(strict_types=1);

namespace Dolifleet\Console\Lang;

use Dolifleet\Console\Settings;
use ValueError;

/**
 * Texts of the console, one PHP file per language returning an array (fleet/lang/<code>.php).
 *
 * The files are loaded with include from the directory of the pilot only (inside the phar once
 * built), never from a directory of the user: an included PHP file is executed code. English is
 * the reference: a key missing in a language is shown in English, never empty nor as a key.
 * Variables go through sprintf, positional (%1$s) when a language changes the order of words.
 */
final class Translator
{
    public const REFERENCE = 'en';

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** @var array<string, string> */
    private array $texts;

    /** @var array<string, string> */
    private array $reference;

    /** @var list<string> Keys asked but absent everywhere: shown in the backstage, fixed in the files. */
    private array $missing = [];

    public function __construct(private readonly string $code, private readonly string $dir = '')
    {
        $this->reference = self::load(self::REFERENCE, $this->directory());
        $this->texts = self::REFERENCE === $code ? $this->reference : self::load($code, $this->directory());
    }

    /**
     * Language chosen, most specific first: option, DOLIFLEET_LANG, settings, locale of the system.
     * An unknown or unavailable code falls back to English.
     */
    public static function resolve(?string $option, ?Settings $settings = null, string $dir = ''): self
    {
        $directory = '' === $dir ? self::defaultDirectory() : $dir;
        $available = array_keys(self::available($directory));
        $candidates = [
            $option,
            self::env('DOLIFLEET_LANG'),
            null !== $settings ? $settings->lang() : null,
            self::env('LC_ALL'),
            self::env('LC_MESSAGES'),
            self::env('LANG'),
        ];
        foreach ($candidates as $candidate) {
            $code = self::normalize((string) $candidate);
            if ('' !== $code && in_array($code, $available, true)) {
                return new self($code, $directory);
            }
        }

        return new self(self::REFERENCE, $directory);
    }

    /**
     * fr_FR.UTF-8, fr-FR, FR -> fr. C and POSIX mean no preference.
     */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (1 !== preg_match('/^([a-z]{2})(?:[_-][a-z]{2})?(?:\.[a-z0-9-]+)?(?:@[a-z]+)?$/', $value, $m)) {
            return '';
        }

        return $m[1];
    }

    /**
     * Languages present, with the share of the reference keys they translate (0 to 100).
     *
     * @return array<string, int>
     */
    public static function available(string $dir = ''): array
    {
        $directory = '' === $dir ? self::defaultDirectory() : $dir;
        $reference = self::load(self::REFERENCE, $directory);
        $languages = [];
        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if (1 !== preg_match('/^[a-z]{2}$/', $code)) {
                continue;
            }
            $texts = self::load($code, $directory);
            $translated = count(array_intersect_key($texts, $reference));
            $languages[$code] = [] === $reference ? 0 : (int) floor(100 * $translated / count($reference));
        }
        ksort($languages);

        return $languages;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function has(string $key): bool
    {
        return isset($this->texts[$key]) || isset($this->reference[$key]);
    }

    public function t(string $key, string|int|float ...$args): string
    {
        $text = $this->texts[$key] ?? $this->reference[$key] ?? null;
        if (null === $text) {
            if ( ! in_array($key, $this->missing, true)) {
                $this->missing[] = $key;
            }

            return $this->reference['missing.text'] ?? '?';
        }
        if ([] === $args) {
            return $text;
        }
        try {
            return vsprintf($text, $args);
        } catch (ValueError $e) {
            // A translation with more parameters than given: shown as is rather than lost.
            return $text;
        }
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    /**
     * Widest text of a key over every language present: columns and key bars are sized on it so
     * that no language breaks the layout.
     */
    public function widest(string $key): int
    {
        $width = 0;
        foreach (array_keys(self::available($this->directory())) as $code) {
            $text = self::load($code, $this->directory())[$key] ?? null;
            if (null !== $text) {
                $width = max($width, mb_strwidth($text));
            }
        }

        return max($width, mb_strwidth($this->t($key)));
    }

    public function number(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, $this->t('format.decimal'), $this->t('format.thousands'));
    }

    /**
     * 412 Mo, 1,2 Go (fr) or 412 MB, 1.2 GB (en).
     */
    public function bytes(int $bytes): string
    {
        $units = ['unit.bytes', 'unit.kb', 'unit.mb', 'unit.gb', 'unit.tb'];
        $value = (float) max(0, $bytes);
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            ++$index;
        }

        return $this->number($value, $value < 10 && $index > 0 ? 1 : 0).' '.$this->t($units[$index]);
    }

    /**
     * Short duration: 45 s, 12 min, 5 h, 9 d (in the language).
     */
    public function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $this->t('duration.seconds', $seconds);
        }
        if ($seconds < 3600) {
            return $this->t('duration.minutes', intdiv($seconds, 60));
        }
        if ($seconds < 172800) {
            return $this->t('duration.hours', intdiv($seconds, 3600));
        }

        return $this->t('duration.days', intdiv($seconds, 86400));
    }

    /**
     * "il y a 2 min", "2 min ago".
     */
    public function ago(int $seconds): string
    {
        return $this->t('duration.ago', $this->duration($seconds));
    }

    public function date(int $timestamp): string
    {
        return date($this->t('format.date'), $timestamp);
    }

    public function time(int $timestamp): string
    {
        return date($this->t('format.time'), $timestamp);
    }

    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 3).'/lang';
    }

    private function directory(): string
    {
        return '' === $this->dir ? self::defaultDirectory() : $this->dir;
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $code, string $dir): array
    {
        $file = $dir.'/'.$code.'.php';
        if (isset(self::$cache[$file])) {
            return self::$cache[$file];
        }
        if (1 !== preg_match('/^[a-z]{2}$/', $code) || ! is_file($file)) {
            return self::$cache[$file] = [];
        }
        $texts = include $file;
        $clean = [];
        foreach (is_array($texts) ? $texts : [] as $key => $text) {
            if (is_string($key) && is_string($text)) {
                $clean[$key] = $text;
            }
        }

        return self::$cache[$file] = $clean;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
