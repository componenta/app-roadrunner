<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Worker;

use Generator;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Spiral\RoadRunner\Http\Exception\StreamStoppedException;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;
use Throwable;

/**
 * Uses the public HTTP transport API rather than PSR7Worker's internal chunkSize.
 * @internal
 */
final readonly class Psr7WorkerAdapter implements PSR7WorkerInterface
{
    public function __construct(
        private PSR7Worker $worker,
        private int $chunkSize,
    ) {
        if ($chunkSize < 0) {
            throw new InvalidArgumentException('RoadRunner HTTP chunk size must not be negative.');
        }
    }

    public function getWorker(): WorkerInterface
    {
        return $this->worker->getWorker();
    }

    public function waitRequest(): ?ServerRequestInterface
    {
        return $this->worker->waitRequest();
    }

    public function respond(ResponseInterface $response): void
    {
        $http = $this->worker->getHttpWorker();
        $status = $response->getStatusCode();

        if ($status < 200) {
            $http->respond($status, '', $response->getHeaders());
            return;
        }

        if ($this->chunkSize === 0) {
            $http->respond($status, $this->readBody($response->getBody()), $response->getHeaders());
            return;
        }

        $failure = null;
        $http->respond(
            $status,
            $this->chunks($response->getBody(), $failure),
            $response->getHeaders(),
        );

        // RoadRunner HTTP may close a stream after a generator exception
        // without propagating it. Do not silently reuse a failed worker.
        if ($failure !== null) {
            throw $failure;
        }
    }

    private function readBody(StreamInterface $body): string
    {
        $size = null;
        if ($body->isSeekable()) {
            $body->rewind();
            $size = $body->getSize();
        }

        // __toString() must not throw under PSR-7 and can hide I/O failures.
        $contents = $body->getContents();

        if ($size !== null) {
            $this->verifySize($size, strlen($contents));
        } elseif (!$body->eof()) {
            throw new RuntimeException('RoadRunner response stream ended before EOF.');
        }

        return $contents;
    }

    private function verifySize(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('RoadRunner response stream size changed during transmission.');
        }
    }

    /**
     * Stream reads can change EOF without changing the stream object.
     * @phpstan-impure
     */
    private function isAtEnd(StreamInterface $body): bool
    {
        return $body->eof();
    }

    /** @return Generator<int, string, mixed, string> */
    private function chunks(StreamInterface $body, ?Throwable &$failure): Generator
    {
        try {
            $body->rewind();
            $size = $body->getSize();
            $readBytes = 0;
            while (!$body->eof()) {
                $chunk = $body->read($this->chunkSize);

                if ($chunk === '') {
                    if (!$this->isAtEnd($body)) {
                        throw new RuntimeException('RoadRunner response stream made no progress before EOF.');
                    }

                    break;
                }

                $readBytes += strlen($chunk);
                if ($size !== null && $readBytes > $size) {
                    throw new RuntimeException('RoadRunner response stream size changed during transmission.');
                }

                if ($this->isAtEnd($body)) {
                    if ($size !== null) {
                        $this->verifySize($size, $readBytes);
                    }

                    return $chunk;
                }

                try {
                    yield $chunk;
                } catch (StreamStoppedException) {
                    // HttpWorker injects cancellation at the suspended yield
                    // and acknowledges the stop after the generator returns.
                    return '';
                }
            }

            if ($size !== null) {
                $this->verifySize($size, $readBytes);
            }

            return '';
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        }
    }
}
