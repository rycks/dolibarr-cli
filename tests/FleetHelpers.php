<?php

declare(strict_types=1);

/**
 * Inventory of the tests: the SQLite test Dolibarr reached through the fake ssh.
 *
 * @param  string  $extra  YAML appended to the main file
 */
function fleetInventory(string $extra = '', string $tags = '[prod]'): string
{
    $dir = DOLICLI_TEST_TMP.'/fleet';
    if ( ! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $account = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
    $yaml = "defaults:\n  parallel: 2\n  protected_tags: [prod]\n\nservers:\n  local-a:\n    php: ".PHP_BINARY."\n    instances:\n"
        ."      dev1:\n        htdocs: ".DOLICLI_DOLPATH."\n        run_as: {$account}\n        tags: {$tags}\n"
        ."      dev2:\n        htdocs: ".DOLICLI_DOLPATH."\n        run_as: {$account}\n        tags: [preprod]\n"
        .$extra;
    file_put_contents($dir.'/inventory.yaml', $yaml);

    return $dir.'/inventory.yaml';
}

/**
 * @param  array<int, string>  $args
 * @param  array<string, string|false>  $env
 * @return array{code: int, out: string, err: string}
 */
function fleet(array $args, ?string $stdin = null, array $env = []): array
{
    $dir = DOLICLI_TEST_TMP.'/fleet';
    $base = [
        'DOLIFLEET_INVENTORY' => $dir.'/inventory.yaml',
        'DOLIFLEET_SSH' => DOLICLI_ROOT.'/tests/Fleet/fake-ssh',
        'DOLIFLEET_BODY' => DOLICLI_TEST_DIST.'/dolicli.php',
        'XDG_CONFIG_HOME' => $dir.'/config',
        'XDG_DATA_HOME' => $dir.'/data',
    ];

    return runProcess(array_merge([PHP_BINARY, DOLICLI_ROOT.'/fleet/bin/dolifleet', '--no-interaction'], $args), $stdin, serverEnvironment(array_merge($base, $env)), DOLICLI_ROOT);
}

/**
 * @param  array<int, string>  $args
 * @return array{code: int, json: mixed, err: string}
 */
function fleetJson(array $args, ?string $stdin = null): array
{
    // --json before the arguments of dolicli (everything after -- goes to dolicli).
    array_splice($args, 1, 0, ['--json']);
    $result = fleet($args, $stdin);

    return ['code' => $result['code'], 'json' => json_decode($result['out'], true), 'err' => $result['err']];
}

/**
 * @return list<array<string, mixed>>
 */
function fleetJournal(): array
{
    $file = DOLICLI_TEST_TMP.'/fleet/data/dolifleet/journal.jsonl';
    if ( ! is_file($file)) {
        return [];
    }

    return array_map(fn (string $line): array => (array) json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
}
