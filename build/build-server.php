<?php

declare(strict_types=1);

/*
 * Build the single-file body of dolicli (dist/dolicli.php) and its launcher (dist/dolicli).
 *
 *   php build/build-server.php [--output=dist] [--version=X]
 *
 * Every source of server/src is concatenated in topological order (parents and
 * interfaces first), each namespace in braces, followed by the global code of
 * server/main.php. The version (git describe) and the SHA256 of the body are injected.
 */

require __DIR__.'/lib/ServerBuilder.php';

$root = dirname(__DIR__);
$options = getopt('', ['output::', 'version::']);
$output = rtrim((string) ($options['output'] ?? 'dist'), '/');
if ( ! str_starts_with($output, '/')) {
    $output = $root.'/'.$output;
}
$version = (string) ($options['version'] ?? '');
if ('' === $version) {
    $described = trim((string) shell_exec('git -C '.escapeshellarg($root).' describe --tags --always --dirty 2>/dev/null'));
    $version = '' !== $described ? $described : 'unknown';
}

try {
    $builder = new ServerBuilder($root.'/server');
    $body = $builder->build($version);
    $problems = ServerBuilder::check($body);
    if ([] !== $problems) {
        fwrite(STDERR, "Forbidden constructions in the body:\n  ".implode("\n  ", $problems)."\n");
        exit(1);
    }

    if ( ! is_dir($output) && ! mkdir($output, 0755, true) && ! is_dir($output)) {
        throw new RuntimeException("Cannot create {$output}");
    }
    if (false === file_put_contents($output.'/dolicli.php', $body)) {
        throw new RuntimeException("Cannot write {$output}/dolicli.php");
    }
    if ( ! copy($root.'/server/launcher.php', $output.'/dolicli') || ! chmod($output.'/dolicli', 0755)) {
        throw new RuntimeException("Cannot write {$output}/dolicli");
    }
    chmod($output.'/dolicli.php', 0644);
} catch (Throwable $e) {
    fwrite(STDERR, 'Build failed: '.$e->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, sprintf("%s/dolicli.php: dolicli %s, %d classes, sha256 %s\n", $output, $version, $builder->classCount(), ServerBuilder::bodyHash($body)));
