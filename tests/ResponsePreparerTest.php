<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Tests\Fixture\FakeBinaryWorker;
use Componenta\App\RoadRunner\Tests\Fixture\NonSeekableStream;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Spiral\RoadRunner\Http\PSR7Worker;

it('preserves SAPI semantics for full, HEAD and bodyless responses', function (): void {
    $factory = new Psr17Factory();
    $preparer = new ResponsePreparer($factory);

    $full = $preparer->prepare(new ServerRequest('GET', '/'), new Response(200, [], 'abcdef'));
    $head = $preparer->prepare(new ServerRequest('HEAD', '/'), new Response(200, [], 'abcdef'));
    $noBody = $preparer->prepare(
        new ServerRequest('GET', '/'),
        new Response(204, ['Content-Type' => 'text/plain', 'Content-Length' => '6'], 'abcdef'),
    );
    $resetContent = $preparer->prepare(
        new ServerRequest('GET', '/'),
        new Response(205, ['Content-Type' => 'text/plain'], 'abcdef'),
    );

    expect($full->getHeaderLine('Content-Length'))->toBe('6')
        ->and($full->getHeaderLine('Accept-Ranges'))->toBe('bytes')
        ->and((string) $full->getBody())->toBe('abcdef')
        ->and($head->getHeaderLine('Content-Length'))->toBe('6')
        ->and((string) $head->getBody())->toBe('')
        ->and($noBody->hasHeader('Content-Type'))->toBeFalse()
        ->and($noBody->hasHeader('Content-Length'))->toBeFalse()
        ->and((string) $noBody->getBody())->toBe('')
        ->and($resetContent->getHeaderLine('Content-Type'))->toBe('text/plain')
        ->and($resetContent->getHeaderLine('Content-Length'))->toBe('6')
        ->and($resetContent->getHeaderLine('Accept-Ranges'))->toBe('bytes')
        ->and((string) $resetContent->getBody())->toBe('abcdef');
});

it('prepares a valid partial response in a bounded seekable stream', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(206, ['Content-Range' => 'bytes 2-4/6'], 'abcdef'),
    );

    expect($prepared->getBody()->isSeekable())->toBeTrue()
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('3')
        ->and((string) $prepared->getBody())->toBe('cde');
});

it('rejects a byte range beyond a known source size', function (): void {
    $preparer = new ResponsePreparer(new Psr17Factory());

    expect(fn () => $preparer->prepare(
        new ServerRequest('GET', '/'),
        new Response(206, ['Content-Range' => 'bytes 2-3/4'], 'abc'),
    ))->toThrow(InvalidArgumentException::class);
});

it('buffers the remaining bytes of a non-seekable response body', function (): void {
    $source = new NonSeekableStream(Stream::create('forward'));
    expect($source->read(2))->toBe('fo');

    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, [], $source),
    );

    expect($prepared->getBody()->isSeekable())->toBeTrue()
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('5')
        ->and($prepared->hasHeader('Accept-Ranges'))->toBeFalse()
        ->and((string) $prepared->getBody())->toBe('rward');
});

it('removes PHP body metadata from delegated file responses', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, [
            'X-Sendfile' => '/srv/report.pdf',
            'Content-Length' => '0',
            'Accept-Ranges' => 'bytes',
        ], 'ignored'),
    );

    expect($prepared->getHeaderLine('X-Sendfile'))->toBe('/srv/report.pdf')
        ->and($prepared->hasHeader('Content-Length'))->toBeFalse()
        ->and($prepared->hasHeader('Accept-Ranges'))->toBeFalse()
        ->and((string) $prepared->getBody())->toBe('');
});

it('rejects malformed and internally inconsistent content ranges', function (): void {
    $preparer = new ResponsePreparer(new Psr17Factory());
    $request = new ServerRequest('GET', '/');

    $malformedUnsatisfied = $preparer->prepare(
        $request,
        new Response(200, ['Content-Range' => 'bytes */garbage'], 'abcdef'),
    );
    $invalidTotal = $preparer->prepare(
        $request,
        new Response(206, ['Content-Range' => 'bytes 2-9/6'], 'abcdef'),
    );
    $caseInsensitive = $preparer->prepare(
        $request,
        new Response(206, ['Content-Range' => 'BYTES 2-4/6'], 'abcdef'),
    );

    expect((string) $malformedUnsatisfied->getBody())->toBe('abcdef')
        ->and($malformedUnsatisfied->getHeaderLine('Content-Length'))->toBe('6')
        ->and((string) $invalidTotal->getBody())->toBe('abcdef')
        ->and((string) $caseInsensitive->getBody())->toBe('cde');
});

it('does not add content length beside transfer encoding', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, ['Transfer-Encoding' => 'chunked'], 'abcdef'),
    );

    expect($prepared->hasHeader('Content-Length'))->toBeFalse();
});

it('is compatible with the official RoadRunner chunked PSR-7 transport', function (): void {
    $psr17 = new Psr17Factory();
    $preparer = new ResponsePreparer($psr17);
    $binaryWorker = new FakeBinaryWorker();
    $worker = new PSR7Worker($binaryWorker, $psr17, $psr17, $psr17);
    $worker->chunkSize = 2;

    $worker->respond($preparer->prepare(
        new ServerRequest('GET', '/'),
        new Response(206, ['Content-Range' => 'bytes 2-4/6'], 'abcdef'),
    ));

    expect(implode('', array_map(
        static fn ($payload): string => $payload->body,
        $binaryWorker->responses,
    )))->toBe('cde');

    $binaryWorker->responses = [];
    $worker->respond($preparer->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, [], new NonSeekableStream(Stream::create('forward'))),
    ));

    expect(implode('', array_map(
        static fn ($payload): string => $payload->body,
        $binaryWorker->responses,
    )))->toBe('forward');
});
