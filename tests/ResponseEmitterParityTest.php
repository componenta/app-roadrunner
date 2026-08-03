<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Tests\Fixture\ResponseParityFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

function emitSapiBody(string $path): string
{
    $program = sprintf(
        'require %s; $response = %s::create(%s); (new \\Componenta\\Http\\Emitter())->emit($response);',
        var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
        ResponseParityFixture::class,
        var_export($path, true),
    );
    $process = proc_open(
        [PHP_BINARY, '-r', $program],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__),
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the SAPI emitter process.');
    }

    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $body === false || $errors === false) {
        throw new RuntimeException(sprintf(
            'SAPI emitter process failed with exit code %d: %s',
            $exitCode,
            $errors === false ? 'unable to read STDERR' : trim($errors),
        ));
    }

    return $body;
}

function prepareRoadRunnerBody(string $path): string
{
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', $path),
        ResponseParityFixture::create($path),
    );

    return (string) $prepared->getBody();
}

it('preserves emitted body semantics across SAPI and RoadRunner runtimes', function (): void {
    foreach ([
        'full response' => '/full',
        '205 response' => '/status/205',
        '206 response' => '/range/206',
        'non-seekable response' => '/stream/non-seekable',
    ] as $case => $path) {
        expect(prepareRoadRunnerBody($path), $case)->toBe(emitSapiBody($path));
    }
});
