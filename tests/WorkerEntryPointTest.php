<?php

declare(strict_types=1);

/** @return array{int, string, string} */
function runRoadRunnerEntryPoint(string $body): array
{
    $root = sys_get_temp_dir() . '/componenta-rr-' . bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root . '/bin');
    mkdir($root . '/vendor');

    try {
        copy(dirname(__DIR__) . '/resources/bin/roadrunner.php', $root . '/bin/roadrunner.php');
        file_put_contents($root . '/vendor/autoload.php', '<?php namespace Componenta\App { enum Scope { case HTTP; } function run($scope, $paths): void { ' . $body . ' } } namespace Componenta\Stdlib { class PathResolver { public function __construct(string $root) {} } }');
        $process = proc_open([PHP_BINARY, '-d', 'log_errors=1', $root . '/bin/roadrunner.php'], [
            0 => ['pipe', 'r'],
            1 => ['file', $root . '/stdout', 'w'],
            2 => ['file', $root . '/stderr', 'w'],
        ], $pipes, $root);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch the worker entry point.');
        }
        fclose($pipes[0]);
        $code = proc_close($process);

        return [$code, (string) file_get_contents($root . '/stdout'), (string) file_get_contents($root . '/stderr')];
    } finally {
        foreach (['bin/roadrunner.php', 'vendor/autoload.php', 'stdout', 'stderr'] as $path) {
            if (is_file($root . '/' . $path)) {
                unlink($root . '/' . $path);
            }
        }
        rmdir($root . '/bin');
        rmdir($root . '/vendor');
        rmdir($root);
    }
}

it('respects suppressed warnings while protecting the protocol output', function (): void {
    [$code, $stdout, $stderr] = runRoadRunnerEntryPoint(
        '@trigger_error("suppressed-warning", E_USER_WARNING); echo "application-output";',
    );

    expect($code)->toBe(0)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('application-output')
        ->not->toContain('suppressed-warning');
});

it('converts enabled warnings to a worker failure', function (): void {
    [$code, $stdout, $stderr] = runRoadRunnerEntryPoint(
        'trigger_error("enabled-warning", E_USER_WARNING);',
    );

    expect($code)->toBe(1)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('enabled-warning');
});

it('prevents requests from removing the permanent protocol output guard', function (): void {
    [$code, $stdout, $stderr] = runRoadRunnerEntryPoint(
        'if (@ob_end_clean()) { exit(2); } echo "guarded-output";',
    );

    expect($code)->toBe(0)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('guarded-output');
});
