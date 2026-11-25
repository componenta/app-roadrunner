<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Tests\Fixture\FakeBinaryWorker;
use Componenta\App\RoadRunner\Tests\Fixture\NonSeekableStream;
use Componenta\App\RoadRunner\Worker\Psr7WorkerAdapter;
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
        ->and($full->hasHeader('Accept-Ranges'))->toBeFalse()
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
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binaryWorker, $psr17, $psr17, $psr17), 2);

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

it('does not consume a non-seekable response body for HEAD', function (): void {
    $source = new NonSeekableStream(Stream::create('forward'));
    $source->read(2);

    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('HEAD', '/'),
        new Response(200, [], $source),
    );

    expect((string) $prepared->getBody())->toBe('')
        ->and($prepared->hasHeader('Content-Length'))->toBeFalse()
        ->and($source->read(5))->toBe('rward');
});

it('preserves explicit HEAD representation metadata without inspecting the body', function (): void {
    $source = new NonSeekableStream(Stream::create('forward'));

    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('HEAD', '/'),
        new Response(200, ['Content-Length' => '7', 'Content-Type' => 'text/plain'], $source),
    );

    expect((string) $prepared->getBody())->toBe('')
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('7')
        ->and($prepared->getHeaderLine('Content-Type'))->toBe('text/plain')
        ->and($source->read(7))->toBe('forward');
});

it('does not advertise byte ranges merely because a response stream is seekable', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, [], 'abc'),
    );

    expect($prepared->hasHeader('Accept-Ranges'))->toBeFalse();
});

it('preserves explicit application range support', function (string $value): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, ['Accept-Ranges' => $value], 'abc'),
    );

    expect($prepared->getHeaderLine('Accept-Ranges'))->toBe($value);
})->with(['bytes', 'none']);

it('preserves explicit representation metadata on 304 without generating a body', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(304, [
            'Content-Length' => '123',
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
            'ETag' => '"v1"',
        ], 'ignored'),
    );

    expect((string) $prepared->getBody())->toBe('')
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('123')
        ->and($prepared->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($prepared->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and($prepared->getHeaderLine('ETag'))->toBe('"v1"');
});

it('removes transfer encoding from responses that cannot carry a message body', function (int $status): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response($status, ['Transfer-Encoding' => 'chunked', 'Content-Length' => '6'], 'abcdef'),
    );

    expect((string) $prepared->getBody())->toBe('')
        ->and($prepared->hasHeader('Transfer-Encoding'))->toBeFalse()
        ->and($prepared->hasHeader('Content-Length'))->toBeFalse();
})->with([100, 103, 204, 205]);

it('never sends content length together with transfer encoding', function (string $method, int $status): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest($method, '/'),
        new Response($status, ['Transfer-Encoding' => 'chunked', 'Content-Length' => '6'], 'abcdef'),
    );

    expect($prepared->hasHeader('Content-Length'))->toBeFalse();
})->with([
    ['GET', 200],
    ['HEAD', 200],
    ['GET', 304],
]);

it('propagates a stream read failure after a chunk has been sent', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 2);
    $source = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $source->method('eof')->willReturn(false);
    $reads = 0;
    $source->method('read')->willReturnCallback(static function () use (&$reads): string {
        if (++$reads === 1) {
            return 'ab';
        }
        throw new RuntimeException('stream failed');
    });

    expect(fn () => $worker->respond(new Response(200, [], $source)))
        ->toThrow(RuntimeException::class, 'stream failed');
    expect($binary->responses[0]->body)->toBe('ab');
});

it('sends informational responses without a generator even when chunking is enabled', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 2);

    $worker->respond(new Response(103));

    expect($binary->responses)->toHaveCount(1)
        ->and($binary->responses[0]->body)->toBe('');
});

it('does not conceal a buffered response read failure behind stream string conversion', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 0);
    $source = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $source->method('isSeekable')->willReturn(true);
    $source->method('getContents')->willThrowException(new RuntimeException('buffered read failed'));

    expect(fn () => $worker->respond(new Response(200, [], $source)))
        ->toThrow(RuntimeException::class, 'buffered read failed');
    expect($binary->responses)->toBe([]);
});

it('transmits the complete seekable body in buffered mode even when the cursor is advanced', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 0);
    $body = Stream::create('abcdef');
    $body->read(2);

    $worker->respond(new Response(200, [], $body));

    expect($binary->responses)->toHaveCount(1)
        ->and($binary->responses[0]->body)->toBe('abcdef');
});

it('rejects inconsistent or malformed explicit content lengths before transport', function (string $value): void {
    expect(fn () => (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, ['Content-Length' => $value], 'abc'),
    ))->toThrow(InvalidArgumentException::class);
})->with(['2', '4', '-1', '1e0', '3, 4', '99999999999999999999999999999']);

it('normalizes repeated identical content lengths without changing body bytes', function (): void {
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, ['Content-Length' => ['003', '3']], 'abc'),
    );

    expect($prepared->getHeader('Content-Length'))->toBe(['3'])
        ->and((string) $prepared->getBody())->toBe('abc');
});

it('validates a declared length against the remaining bytes of a non-seekable body', function (): void {
    $source = new NonSeekableStream(Stream::create('abcdef'));
    $source->read(2);

    expect(fn () => (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, ['Content-Length' => '6'], $source),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid representation content length on HEAD and 304 responses', function (string $method, int $status, string $length): void {
    expect(fn () => (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest($method, '/'),
        new Response($status, ['Content-Length' => $length]),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    ['HEAD', 200, '-1'],
    ['HEAD', 200, '3, 4'],
    ['GET', 304, '3, 4'],
    ['GET', 304, '1e3'],
]);

it('normalizes equivalent representation lengths without reading a suppressed body', function (string $method, int $status): void {
    $stream = new NonSeekableStream(Stream::create('abc'));
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest($method, '/'),
        new Response($status, ['Content-Length' => ['012', '12']], $stream),
    );

    expect($prepared->getHeader('Content-Length'))->toBe(['12'])
        ->and((string) $prepared->getBody())->toBe('')
        ->and($stream->read(3))->toBe('abc');
})->with([
    ['HEAD', 200],
    ['GET', 304],
]);

it('rejects a declared length that differs from an initially unknown seekable body size', function (string $length): void {
    $body = Stream::create('abcdef');
    $source = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $source->method('isSeekable')->willReturn(true);
    $source->method('isReadable')->willReturn(true);
    $source->method('getSize')->willReturn(null);
    $source->method('rewind')->willReturnCallback(static fn () => $body->rewind());
    $source->method('eof')->willReturnCallback(static fn (): bool => $body->eof());
    $source->method('read')->willReturnCallback(static fn (int $length): string => $body->read($length));

    expect(fn () => (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, ['Content-Length' => $length], $source),
    ))->toThrow(InvalidArgumentException::class, 'RoadRunner response Content-Length does not match the body size.');
})->with(['5', '7']);

it('preserves a correct declared length when the original seekable body size is unknown', function (): void {
    $body = Stream::create('abcdef');
    $body->read(2);
    $source = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $source->method('isSeekable')->willReturn(true);
    $source->method('isReadable')->willReturn(true);
    $source->method('getSize')->willReturn(null);
    $source->method('rewind')->willReturnCallback(static fn () => $body->rewind());
    $source->method('eof')->willReturnCallback(static fn (): bool => $body->eof());
    $source->method('read')->willReturnCallback(static fn (int $length): string => $body->read($length));
    $source->method('getContents')->willReturnCallback(static fn (): string => $body->getContents());

    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, ['Content-Length' => '6'], $source),
    );

    $prepared->getBody()->rewind();

    expect($prepared->getHeaderLine('Content-Length'))->toBe('6')
        ->and($prepared->getBody()->getContents())->toBe('abcdef');
});

it('does not read an unknown-size seekable body without a declared length', function (): void {
    $source = $this->createStub(\Psr\Http\Message\StreamInterface::class);
    $source->method('isSeekable')->willReturn(true);
    $source->method('isReadable')->willReturn(true);
    $source->method('getSize')->willReturn(null);
    $source->method('read')->willThrowException(new RuntimeException('must not read'));

    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        new ServerRequest('GET', '/'),
        new Response(200, [], $source),
    );

    expect($prepared->hasHeader('Content-Length'))->toBeFalse();
});

it('suppresses content only for the case-sensitive HEAD method', function (string $method, string $expected): void {
    $request = new ServerRequest($method, '/');
    $prepared = (new ResponsePreparer(new Psr17Factory()))->prepare(
        $request,
        new Response(200, [], 'abcdef'),
    );

    expect($request->getMethod())->toBe($method)
        ->and((string) $prepared->getBody())->toBe($expected)
        ->and($prepared->getHeaderLine('Content-Length'))->toBe('6');
})->with([
    'standard HEAD' => ['HEAD', ''],
    'lowercase extension method' => ['head', 'abcdef'],
    'mixed-case extension method' => ['Head', 'abcdef'],
]);
