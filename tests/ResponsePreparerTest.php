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
        ->and($resetContent->hasHeader('Content-Length'))->toBeFalse()
        ->and((string) $resetContent->getBody())->toBe('');
});

it('preserves the prepared partial response body', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(206, ['Content-Range' => 'bytes 2-4/6'], 'cde'),
    );

    expect($prepared->getBody()->isSeekable())->toBeTrue()
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('3')
        ->and((string) $prepared->getBody())->toBe('cde');
});

it('preserves a selected range whose representation offset exceeds its body size', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(206, ['Content-Range' => 'bytes 10-12/20'], 'abc'),
    );

    expect((string) $prepared->getBody())->toBe('abc')
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('3')
        ->and($prepared->getHeaderLine('Content-Range'))->toBe('bytes 10-12/20');
});

it('preserves the explanation and metadata of an unsatisfied range response', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(416, ['Content-Type' => 'text/plain', 'Content-Range' => 'bytes */20'], 'range unavailable'),
    );

    expect((string) $prepared->getBody())->toBe('range unavailable')
        ->and($prepared->getHeaderLine('Content-Type'))->toBe('text/plain')
        ->and($prepared->getHeaderLine('Content-Range'))->toBe('bytes */20')
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('17');
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

it('buffers a non-seekable selected range without interpreting representation offsets', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(206, ['Content-Range' => 'bytes 10-12/20'], new NonSeekableStream(Stream::create('abc'))),
    );

    expect((string) $prepared->getBody())->toBe('abc')
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('3')
        ->and($prepared->getBody()->isSeekable())->toBeTrue();
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
        new Response(206, ['Content-Range' => 'bytes 2-4/6'], 'cde'),
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

it('preserves Content-Location and ETag on a bodyless 304 response', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/documents/one'),
        new Response(304, ['Content-Location' => '/documents/one', 'ETag' => '"one"'], 'discarded'),
    );

    expect($prepared->getHeaderLine('Content-Location'))->toBe('/documents/one')
        ->and($prepared->getHeaderLine('ETag'))->toBe('"one"')
        ->and((string) $prepared->getBody())->toBe('');
});

it('rejects a non-seekable stream that makes no progress before EOF', function (): void {
    $source = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $source->method('eof')->willReturn(false);
    $source->method('read')->willReturn('');

    expect(fn () => (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, [], new NonSeekableStream($source)),
    ))->toThrow(RuntimeException::class, 'RoadRunner response stream made no progress before EOF.');
});

it('accepts an empty read when the stream has reached EOF', function (): void {
    $source = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $source->method('eof')->willReturn(false, true);
    $source->method('read')->willReturn('');

    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, [], new NonSeekableStream($source)),
    );

    expect((string) $prepared->getBody())->toBe('')
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('0');
});
