<?php

declare(strict_types=1);

use Dolifleet\Console\Lang\Translator;
use Dolifleet\Console\Settings;

/**
 * @return array<string, list<string>> key => placeholders in order of position
 */
function langPlaceholders(string $code): array
{
    $texts = include Translator::defaultDirectory().'/'.$code.'.php';
    $placeholders = [];
    foreach ($texts as $key => $text) {
        preg_match_all('/%(?:(\d+)\$)?([sdf])/', $text, $m, PREG_SET_ORDER);
        $found = [];
        foreach ($m as $index => $match) {
            $position = '' !== $match[1] ? (int) $match[1] : $index + 1;
            $found[$position] = $match[2];
        }
        ksort($found);
        $placeholders[$key] = array_values($found);
    }

    return $placeholders;
}

afterEach(function (): void {
    foreach (['DOLIFLEET_LANG', 'LC_ALL', 'LC_MESSAGES', 'LANG'] as $name) {
        putenv($name);
    }
});

it('has the same keys and the same sprintf parameters in every language', function (): void {
    $reference = langPlaceholders('en');
    foreach (array_keys(Translator::available()) as $code) {
        $texts = langPlaceholders($code);
        expect(array_keys($texts))->toEqualCanonicalizing(array_keys($reference), "keys of {$code}");
        foreach ($reference as $key => $placeholders) {
            expect($texts[$key])->toBe($placeholders, "parameters of {$key} in {$code}");
        }
    }
    expect(Translator::available())->toBe(['en' => 100, 'fr' => 100]);
});

it('knows every key the console asks for', function (): void {
    $reference = include Translator::defaultDirectory().'/en.php';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DOLICLI_ROOT.'/fleet/src/Console'));
    $used = [];
    foreach ($files as $file) {
        if ($file->isFile() && 'php' === $file->getExtension()) {
            preg_match_all("/->t\\('([a-z0-9_.-]+)'/", (string) file_get_contents($file->getPathname()), $m);
            $used = array_merge($used, $m[1]);
        }
    }
    $missing = array_values(array_diff(array_unique($used), array_keys($reference)));

    expect($missing)->toBe([]);
});

it('chooses the language from the option, the environment, the settings, then the locale', function (): void {
    $dir = DOLICLI_TEST_TMP.'/console-settings';
    @mkdir($dir, 0700, true);
    $settings = Settings::load($dir.'/settings.yaml')->withLang('fr');
    $settings->save();
    putenv('LANG=en_US.UTF-8');

    expect(Translator::resolve('en', $settings)->code())->toBe('en')
        ->and(Translator::resolve(null, $settings)->code())->toBe('fr')
        ->and(Translator::resolve(null, Settings::load($dir.'/settings.yaml'))->code())->toBe('fr')
        ->and(Translator::resolve(null, Settings::load($dir.'/none.yaml'))->code())->toBe('en')
        ->and(Translator::resolve('xx', null)->code())->toBe('en')
        ->and(fileperms($dir.'/settings.yaml') & 0777)->toBe(0600);

    putenv('LANG=fr_FR.UTF-8');
    expect(Translator::resolve(null, null)->code())->toBe('fr');
    putenv('DOLIFLEET_LANG=en');
    expect(Translator::resolve(null, null)->code())->toBe('en');
});

it('falls back to English for a key missing in a language, and records keys missing everywhere', function (): void {
    $dir = DOLICLI_TEST_TMP.'/console-lang';
    @mkdir($dir, 0700, true);
    file_put_contents($dir.'/en.php', "<?php return ['missing.text' => '(untranslated)', 'a' => 'Apple %s', 'b' => 'Banana'];");
    file_put_contents($dir.'/de.php', "<?php return ['a' => 'Apfel %s'];");
    $german = new Translator('de', $dir);

    expect($german->t('a', 'rot'))->toBe('Apfel rot')
        ->and($german->t('b'))->toBe('Banana')
        ->and($german->t('nope'))->toBe('(untranslated)')
        ->and($german->missing())->toBe(['nope'])
        ->and(Translator::available($dir))->toBe(['de' => 33, 'en' => 100]);
});

it('formats sizes, durations and dates in the language', function (): void {
    $fr = new Translator('fr');
    $en = new Translator('en');

    expect($fr->bytes(1288490188))->toBe('1,2 Go')
        ->and($en->bytes(1288490188))->toBe('1.2 GB')
        ->and($fr->bytes(432013312))->toBe('412 Mo')
        ->and($fr->duration(9 * 86400 + 5))->toBe('9 j')
        ->and($en->ago(120))->toBe('2 min ago')
        ->and($fr->ago(120))->toBe('il y a 2 min')
        ->and($fr->date(mktime(14, 2, 0, 11, 17, 2026)))->toBe('17/11/2026 14:02');
});
