<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Tests\Fixture\ResponseParityFixture;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

function emitSapiBody(string $path): string
{
    $program = sprintf(
        '$loader = require %s; $loader->addPsr4(%s, %s); $response = %s::create(%s); (new \\Componenta\\Http\\Emitter())->emit($response);',
        var_export(dirname((new ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php', true),
        var_export('Componenta\\App\\RoadRunner\\Tests\\', true),
        var_export(__DIR__, true),
        ResponseParityFixture::class,
        var_export($path, true),
    );
    $process = proc_open(
        [PHP_BINARY, '-c', php_ini_loaded_file() ?: '', '-d', 'display_errors=stderr', '-r', $program],
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

it('preserves the expected body across SAPI and RoadRunner runtimes', function (string $path, string $expected): void {
    expect(emitSapiBody($path))->toBe($expected)
        ->and(prepareRoadRunnerBody($path))->toBe($expected);
})->with([
    'full response' => ['/full', 'abcdef'],
    'reset content' => ['/status/205', ''],
    'selected range' => ['/range/206', 'cde'],
    'range error explanation' => ['/range/416', 'range unavailable'],
    'non-seekable response' => ['/stream/non-seekable', 'abcdef'],
]);
