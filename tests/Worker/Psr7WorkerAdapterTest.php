<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Tests\Fixture\FakeBinaryWorker;
use Componenta\App\RoadRunner\Worker\Psr7WorkerAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\StreamInterface;
use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Message\Command\StreamStop;

it('acknowledges a client cancellation and can transmit a subsequent response', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $binary->pending = new StreamStop('');
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 2);

    $worker->respond(new Response(200, [], 'abcdef'));

    expect($binary->responses)->toHaveCount(1)
        ->and($binary->responses[0]->body)->toBe('')
        ->and($binary->responses[0]->header)->toBe('');

    $binary->responses = [];
    $worker->respond(new Response(200, [], 'next'));

    expect(implode('', array_map(static fn ($payload): string => $payload->body, $binary->responses)))->toBe('next');
});

it('does not mistake a stream read exception for a client cancellation', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 2);
    $stream = $this->createStub(StreamInterface::class);
    $stream->method('eof')->willReturn(false);
    $stream->method('read')->willThrowException(new StreamStoppedException());

    expect(fn () => $worker->respond(new Response(200, [], $stream)))
        ->toThrow(StreamStoppedException::class);
    expect($binary->responses)->toBe([]);
});

it('rejects a buffered read that returns fewer or more bytes than the stream size', function (string $contents): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 0);
    $stream = $this->createStub(StreamInterface::class);
    $stream->method('isSeekable')->willReturn(true);
    $stream->method('getSize')->willReturn(3);
    $stream->method('getContents')->willReturn($contents);

    expect(fn () => $worker->respond(new Response(200, [], $stream)))
        ->toThrow(RuntimeException::class, 'RoadRunner response stream size changed during transmission.');
    expect($binary->responses)->toBe([]);
})->with(['ab', 'abcd']);

it('rejects an incomplete buffered read with unknown size', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 0);
    $stream = $this->createStub(StreamInterface::class);
    $stream->method('isSeekable')->willReturn(true);
    $stream->method('getSize')->willReturn(null);
    $stream->method('getContents')->willReturn('ab');
    $stream->method('eof')->willReturn(false);

    expect(fn () => $worker->respond(new Response(200, [], $stream)))
        ->toThrow(RuntimeException::class, 'RoadRunner response stream ended before EOF.');
    expect($binary->responses)->toBe([]);
});

it('rejects an early EOF in a chunked response with a known size', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 2);
    $stream = $this->createStub(StreamInterface::class);
    $stream->method('getSize')->willReturn(3);
    $stream->method('eof')->willReturn(false, true);
    $stream->method('read')->willReturn('ab');

    expect(fn () => $worker->respond(new Response(200, [], $stream)))
        ->toThrow(RuntimeException::class, 'RoadRunner response stream size changed during transmission.');
    expect($binary->responses)->toBe([]);
});

it('does not send bytes exceeding a known streamed body size', function (): void {
    $factory = new Psr17Factory();
    $binary = new FakeBinaryWorker();
    $worker = new Psr7WorkerAdapter(new PSR7Worker($binary, $factory, $factory, $factory), 2);
    $stream = $this->createStub(StreamInterface::class);
    $stream->method('getSize')->willReturn(3);
    $stream->method('eof')->willReturn(false);
    $stream->method('read')->willReturn('ab', 'cd');

    expect(fn () => $worker->respond(new Response(200, [], $stream)))
        ->toThrow(RuntimeException::class, 'RoadRunner response stream size changed during transmission.');
    expect(implode('', array_map(static fn ($payload): string => $payload->body, $binary->responses)))->toBe('ab');
});
