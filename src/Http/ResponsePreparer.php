<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Converts Componenta responses to the shape expected by RoadRunner.
 */
final readonly class ResponsePreparer
{
    private const int COPY_CHUNK_SIZE = 8192;

    /** @var list<int> */
    private const array BODYLESS_STATUSES = [100, 101, 102, 103, 204, 205, 304];

    public function __construct(
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function prepare(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        if ($response->hasHeader('Transfer-Encoding') && $response->hasHeader('Content-Length')) {
            $response = $response->withoutHeader('Content-Length');
        }

        return $this->prepareByStatus(
            $response,
            strtoupper($request->getMethod()) === 'HEAD',
        );
    }

    private function prepareByStatus(ResponseInterface $response, bool $head): ResponseInterface
    {
        $status = $response->getStatusCode();

        if (
            in_array($status, self::BODYLESS_STATUSES, true)
            || $status < 200
        ) {
            return $this->prepareBodyless($response);
        }

        if ($response->hasHeader('X-Sendfile') || $response->hasHeader('X-Accel-Redirect')) {
            return $response
                ->withoutHeader('Content-Length')
                ->withoutHeader('Accept-Ranges')
                ->withBody($this->streamFactory->createStream());
        }

        if ($head) {
            return $this->prepareHead($response);
        }

        return $this->prepareFull($response);
    }

    private function prepareBodyless(ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() === 304) {
            // Metadata describes the selected representation, not a 304 body.
            // Preserve values supplied by the application; never infer them.
            return $response
                ->withoutHeader('X-Sendfile')
                ->withoutHeader('X-Accel-Redirect')
                ->withBody($this->streamFactory->createStream());
        }

        return $response
            ->withoutHeader('Content-Location')
            ->withoutHeader('Transfer-Encoding')
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length')
            ->withoutHeader('Content-Encoding')
            ->withoutHeader('Content-Language')
            ->withoutHeader('Content-MD5')
            ->withoutHeader('Content-Range')
            ->withoutHeader('X-Sendfile')
            ->withoutHeader('X-Accel-Redirect')
            ->withBody($this->streamFactory->createStream());
    }

    private function prepareHead(ResponseInterface $response): ResponseInterface
    {
        $body = $response->getBody();

        // A non-seekable stream may be partially consumed or have unknown
        // length. Do not read it solely to compute optional HEAD metadata.
        if ($body->isSeekable()
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
            && ($size = $body->getSize()) !== null
        ) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }

        return $response->withBody($this->streamFactory->createStream());
    }

    private function prepareFull(ResponseInterface $response): ResponseInterface
    {
        $body = $response->getBody();

        if (!$body->isReadable()) {
            throw new InvalidArgumentException('RoadRunner response body must be readable.');
        }

        if (!$body->isSeekable()) {
            $body = $this->copyToSeekableStream($body);
            $response = $response->withBody($body);
        }

        $size = $body->getSize();

        if (
            $size !== null
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
        ) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }

        return $response;
    }

    private function copyToSeekableStream(StreamInterface $source): StreamInterface
    {
        $target = $this->streamFactory->createStream();

        while (!$source->eof()) {
            $chunk = $source->read(self::COPY_CHUNK_SIZE);

            if ($chunk === '') {
                if (!$this->isAtEnd($source)) {
                    throw new RuntimeException('RoadRunner response stream made no progress before EOF.');
                }

                break;
            }

            $this->writeAll($target, $chunk);
        }

        $target->rewind();

        return $target;
    }

    /**
     * A read can change EOF even if the stream object remains the same.
     * @phpstan-impure
     */
    private function isAtEnd(StreamInterface $source): bool
    {
        return $source->eof();
    }

    private function writeAll(StreamInterface $target, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = $target->write(substr($contents, $offset));

            if ($written <= 0) {
                throw new RuntimeException('Unable to buffer a RoadRunner response body.');
            }

            $offset += $written;
        }
    }
}
