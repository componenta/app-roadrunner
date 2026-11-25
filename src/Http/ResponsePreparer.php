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
        $response = $this->prepareByStatus($response);

        // The Go server suppresses a HEAD body at the socket layer, but
        // removing it here avoids transferring the bytes through Goridge.
        return strtoupper($request->getMethod()) === 'HEAD'
            ? $response->withBody($this->streamFactory->createStream())
            : $response;
    }

    private function prepareByStatus(ResponseInterface $response): ResponseInterface
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

        return $this->prepareFull($response);
    }

    private function prepareBodyless(ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() !== 304) {
            $response = $response->withoutHeader('Content-Location');
        }

        return $response
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

    private function prepareFull(ResponseInterface $response): ResponseInterface
    {
        $body = $response->getBody();
        $supportsRanges = $body->isSeekable();

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

        if (!$response->hasHeader('Accept-Ranges') && $size !== null && $supportsRanges) {
            $response = $response->withHeader('Accept-Ranges', 'bytes');
        }

        return $response;
    }

    private function copyToSeekableStream(StreamInterface $source): StreamInterface
    {
        $target = $this->streamFactory->createStream();

        while (!$source->eof()) {
            $chunk = $source->read(self::COPY_CHUNK_SIZE);

            if ($chunk === '') {
                if (!$source->eof()) {
                    throw new RuntimeException('RoadRunner response stream made no progress before EOF.');
                }

                break;
            }

            $this->writeAll($target, $chunk);
        }

        $target->rewind();

        return $target;
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
