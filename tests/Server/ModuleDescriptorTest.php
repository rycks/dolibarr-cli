<?php

declare(strict_types=1);

use Dolifleet\Remote\Body;

/**
 * Isolated instance whose custom/ is a private directory holding the given module descriptors
 * (custom/<name>/core/modules/mod<Name>.class.php).
 *
 * @param  array<string, string>  $descriptors  Module name => PHP code of its descriptor
 * @return array{htdocs: string, files: array<string, string>}
 */
function instanceWithDescriptors(array $descriptors): array
{
    $instance = isolatedInstance();
    $custom = $instance['htdocs'].'/custom';
    unlink($custom);
    mkdir($custom);
    $files = [];
    foreach ($descriptors as $name => $code) {
        $dir = $custom.'/'.strtolower($name).'/core/modules';
        mkdir($dir, 0755, true);
        $files[$name] = $dir.'/mod'.$name.'.class.php';
        file_put_contents($files[$name], $code);
    }

    return ['htdocs' => $instance['htdocs'], 'files' => $files];
}

function healthyDescriptor(): string
{
    return <<<'PHP'
        <?php
        echo "Healthy module talking while it loads\n";

        class modHealthy extends DolibarrModules
        {
            public function __construct($db)
            {
                $this->db = $db;
                $this->numero = 500100;
                $this->rights_class = 'healthy';
                $this->family = 'other';
                $this->name = 'Healthy';
                $this->description = 'Test module';
                $this->version = '1.0';
                $this->const_name = 'MAIN_MODULE_HEALTHY';
                $this->editor_name = 'Test';
            }
        }
        PHP;
}

function stopperDescriptor(): string
{
    return "<?php\necho \"first line\\n\";\nfor (\$i = 1; \$i <= 12; \$i++) {\n    echo \"line {\$i}\\n\";\n}\necho \"<b>Licence check failed</b> for Stopper\\n\";\nexit(0);\n";
}

/**
 * Run the body in the ephemeral mode with the stream of the pilot (body kept as a string).
 *
 * @param  array<int, string>  $args
 * @return array{code: int, out: string, err: string}
 */
function serverPilotStream(array $args, string $php = PHP_BINARY): array
{
    $stream = (new Body(DOLICLI_TEST_DIST.'/dolicli.php'))->stream($args);

    return runProcess([$php, '--'], $stream, serverEnvironment([]), DOLICLI_ROOT);
}

/**
 * Exit code of a module list answered by a child process when a descriptor stopped PHP with a
 * fatal error: before PHP 8, the exit code stays 255 after a fatal error, whatever the shutdown
 * function does (the answer itself is complete).
 */
function exitCodeAfterFatal(string $php): int
{
    return version_compare((string) shell_exec(escapeshellarg($php).' -r "echo PHP_VERSION;"'), '8.0.0', '<') ? 255 : 0;
}

/**
 * @param  array<string, mixed>  $envelope
 * @return array<string, array<string, mixed>>
 */
function excludedByClass(array $envelope): array
{
    $excluded = array_column($envelope['data']['excluded'], null, 'class');
    ksort($excluded);

    return $excluded;
}

it('lists the modules without the descriptors that stop PHP or throw, and names them', function (string $php): void {
    $instance = instanceWithDescriptors([
        'Healthy' => healthyDescriptor(),
        'Stopper' => stopperDescriptor(),
        'Thrower' => "<?php\ndolicli_test_undefined_function();\n",
        'Redeclare' => "<?php\nfunction dol_now() { return 0; }\n",
    ]);

    $result = serverJson(['module', 'list', '--dolpath='.$instance['htdocs']], null, [], $php);
    $envelope = $result['json'];
    $classes = array_column($envelope['data']['modules'], 'class');
    $excluded = excludedByClass($envelope);

    expect($result['code'])->toBe(exitCodeAfterFatal($php))
        ->and($envelope['ok'])->toBeTrue()
        ->and($classes)->toContain('modHealthy', 'modUser', 'modFacture')
        ->and($classes)->not->toContain('modStopper', 'modThrower', 'modRedeclare')
        ->and($envelope['data']['count'])->toBe(count($classes))
        ->and(array_keys($excluded))->toBe(['modRedeclare', 'modStopper', 'modThrower'])
        ->and($excluded['modStopper'])->toBe(['class' => 'modStopper', 'file' => $instance['files']['Stopper'], 'reason' => 'ended the process (exit or die)'])
        ->and($excluded['modThrower']['file'])->toBe($instance['files']['Thrower'])
        ->and($excluded['modThrower']['reason'])->toContain('dolicli_test_undefined_function')
        ->and($excluded['modRedeclare']['file'])->toBe($instance['files']['Redeclare'])
        ->and($excluded['modRedeclare']['reason'])->toStartWith('stopped PHP with a fatal error: ')->toContain('dol_now')
        ->and(array_unique(array_column($envelope['warnings'], 'code')))->toBe(['MODULE_DESCRIPTOR_FAILED'])
        ->and(count($envelope['warnings']))->toBe(3)
        ->and(array_column(array_column($envelope['warnings'], 'details'), 'file'))->toContain($instance['files']['Stopper'], $instance['files']['Thrower'], $instance['files']['Redeclare'])
        ->and($result['err'])->not->toContain('Licence check failed');
})->with(fn (): array => phpMatrix(true));

it('answers module list in text, the left out modules in warnings', function (): void {
    $instance = instanceWithDescriptors(['Healthy' => healthyDescriptor(), 'Stopper' => stopperDescriptor()]);

    $result = server(['module', 'list', '--external', '--dolpath='.$instance['htdocs'], '--format=text']);

    expect($result['code'])->toBe(0)
        ->and($result['out'])->toContain('modHealthy')
        ->and($result['out'])->not->toContain('modStopper')
        ->and($result['out'])->not->toContain('talking while it loads')
        ->and($result['err'])->toContain('Module modStopper left out of the list: its descriptor '.$instance['files']['Stopper'].' ended the process (exit or die).');
});

it('relaunches module list in the ephemeral mode from the copy of the body held in memory', function (string $php): void {
    $instance = instanceWithDescriptors([
        'Healthy' => healthyDescriptor(),
        'Stopper' => stopperDescriptor(),
        'Redeclare' => "<?php\nfunction dol_now() { return 0; }\n",
    ]);

    $result = serverPilotStream(['module', 'list', '--format=json', '--dolpath='.$instance['htdocs']], $php);
    assertMatchesSchema($result['out'], 'envelope');
    $envelope = json_decode($result['out'], true);

    expect($result['code'])->toBe(exitCodeAfterFatal($php))
        ->and($envelope['ok'])->toBeTrue()
        ->and(array_column($envelope['data']['modules'], 'class'))->toContain('modHealthy', 'modUser')
        ->and(array_keys(excludedByClass($envelope)))->toBe(['modRedeclare', 'modStopper'])
        ->and(array_column($envelope['warnings'], 'code'))->toBe(['MODULE_DESCRIPTOR_FAILED', 'MODULE_DESCRIPTOR_FAILED']);
})->with(fn (): array => phpMatrix(true));

it('names the descriptor when the body came on stdin without a copy of itself', function (): void {
    $instance = instanceWithDescriptors(['Stopper' => stopperDescriptor()]);

    $result = serverEphemeral(['module', 'list', '--format=json', '--dolpath='.$instance['htdocs']]);
    assertMatchesSchema($result['out'], 'envelope');
    $error = json_decode($result['out'], true)['errors'][0];

    expect($result['code'])->toBe(3)
        ->and($error['code'])->toBe('PREMATURE_EXIT')
        ->and($error['message'])->toContain('while loading '.$instance['files']['Stopper'])
        ->and($error['message'])->toContain('cannot run again without it')
        ->and($error['details']['file'])->toBe($instance['files']['Stopper'])
        ->and($error['details']['module'])->toBe('modStopper')
        ->and($error['details']['output'])->toHaveCount(10)
        ->and($error['details']['output'][0])->toBe('line 4')
        ->and($error['details']['output'][9])->toBe('Licence check failed for Stopper');
});

it('names the descriptor that stops PHP in the other commands that load the descriptors', function (string $code, string $error): void {
    $instance = instanceWithDescriptors(['Healthy' => healthyDescriptor(), 'Culprit' => $code]);

    $result = serverJson(['module', 'activate', 'name=Healthy', '--dry-run', '--dolpath='.$instance['htdocs']]);
    $failure = $result['json']['errors'][0];

    expect($result['code'])->toBe(3)
        ->and($failure['code'])->toBe($error)
        ->and($failure['message'])->toContain('while loading '.$instance['files']['Culprit'].' (module modCulprit)')
        ->and($failure['details'])->toMatchArray(['file' => $instance['files']['Culprit'], 'module' => 'modCulprit'])
        ->and($result['json']['data'])->toBe([]);
})->with([
    'exit' => ["<?php\necho 'bye';\nexit(0);\n", 'PREMATURE_EXIT'],
    'fatal error' => ["<?php\nfunction dol_now() { return 0; }\n", 'FATAL_ERROR'],
    'exception' => ["<?php\ndolicli_test_undefined_function();\n", 'FATAL_ERROR'],
]);

it('stops relaunching module list after five left out descriptors', function (): void {
    $descriptors = [];
    foreach (['Aaa', 'Bbb', 'Ccc', 'Ddd', 'Eee', 'Fff'] as $name) {
        $descriptors[$name] = "<?php\nexit(0);\n";
    }
    $instance = instanceWithDescriptors($descriptors);

    $result = serverJson(['module', 'list', '--dolpath='.$instance['htdocs']]);
    $failure = $result['json']['errors'][0];

    expect($result['code'])->toBe(3)
        ->and($failure['code'])->toBe('PREMATURE_EXIT')
        ->and($failure['message'])->toContain('5 module descriptors already left out, no further attempt')
        ->and(in_array($failure['details']['file'], $instance['files'], true))->toBeTrue();
});

it('lists the modules with an empty excluded list when every descriptor loads', function (): void {
    $data = serverJson(['module', 'list', '--external'])['json'];

    expect($data['data']['excluded'])->toBe([])
        ->and($data['warnings'])->toBe([]);
});
