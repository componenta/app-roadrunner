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
    private const array BODYLESS_STATUSES = [100, 101, 102, 103, 204, 304, 416];

    public function __construct(
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function prepare(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $response = $this->prepareByStatusAndRange($response);

        // The Go server suppresses a HEAD body at the socket layer, but
        // removing it here avoids transferring the bytes through Goridge.
        return strtoupper($request->getMethod()) === 'HEAD'
            ? $response->withBody($this->streamFactory->createStream())
            : $response;
    }

    private function prepareByStatusAndRange(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();
        $contentRange = trim($response->getHeaderLine('Content-Range'));

        if (
            in_array($status, self::BODYLESS_STATUSES, true)
            || $status < 200
            || preg_match('/^bytes \*\/\d+$/i', $contentRange) === 1
        ) {
            return $this->prepareBodyless($response);
        }

        if ($response->hasHeader('X-Sendfile') || $response->hasHeader('X-Accel-Redirect')) {
            return $response
                ->withoutHeader('Content-Length')
                ->withoutHeader('Accept-Ranges')
                ->withBody($this->streamFactory->createStream());
        }

        return $status === 206 && $contentRange !== ''
            ? $this->preparePartial($response, $contentRange)
            : $this->prepareFull($response);
    }

    private function prepareBodyless(ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() === 416) {
            $response = $response
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Encoding')
                ->withoutHeader('Content-Language')
                ->withoutHeader('Content-Location')
                ->withoutHeader('Content-MD5')
                ->withHeader('Content-Length', '0');
        } else {
            $response = $response
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length')
                ->withoutHeader('Content-Encoding')
                ->withoutHeader('Content-Language')
                ->withoutHeader('Content-Location')
                ->withoutHeader('Content-MD5')
                ->withoutHeader('Content-Range');
        }

        return $response
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

    private function preparePartial(ResponseInterface $response, string $contentRange): ResponseInterface
    {
        $body = $response->getBody();

        if (!$body->isReadable()) {
            throw new InvalidArgumentException('RoadRunner partial response body must be readable.');
        }

        $range = $this->parseContentRange($contentRange);

        if (
            $range !== null
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
        ) {
            $response = $response->withHeader('Content-Length', (string) $range['length']);
        }

        if (!$response->hasHeader('Accept-Ranges')) {
            $response = $response->withHeader('Accept-Ranges', 'bytes');
        }

        if ($range !== null && $body->isSeekable()) {
            return $response->withBody($this->copyToSeekableStream(
                $body,
                $range['start'],
                $range['length'],
            ));
        }

        return $body->isSeekable()
            ? $response
            : $response->withBody($this->copyToSeekableStream($body));
    }

    private function copyToSeekableStream(
        StreamInterface $source,
        ?int $start = null,
        ?int $length = null,
    ): StreamInterface {
        if ($start !== null) {
            $size = $source->getSize();

            if ($size !== null && ($start > $size || $length > $size - $start)) {
                throw new InvalidArgumentException(sprintf(
                    'Response range starting at %d with length %d exceeds body size %d.',
                    $start,
                    $length,
                    $size,
                ));
            }

            $source->seek($start);
        }

        $target = $this->streamFactory->createStream();
        $remaining = $length;

        while (($remaining === null || $remaining > 0) && !$source->eof()) {
            $chunk = $source->read(
                $remaining === null
                    ? self::COPY_CHUNK_SIZE
                    : min(self::COPY_CHUNK_SIZE, $remaining),
            );

            if ($chunk === '') {
                break;
            }

            $this->writeAll($target, $chunk);

            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
        }

        if ($remaining !== null && $remaining > 0) {
            throw new InvalidArgumentException(sprintf(
                'Response body ended %d byte(s) before the declared range.',
                $remaining,
            ));
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

    /** @return array{start: int, length: int}|null */
    private function parseContentRange(string $header): ?array
    {
        if (preg_match('/^bytes (\d+)-(\d+)\/(\d+|\*)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        $start = $this->parseUnsignedInteger($matches[1]);
        $end = $this->parseUnsignedInteger($matches[2]);

        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        if ($matches[3] !== '*') {
            $total = $this->parseUnsignedInteger($matches[3]);

            if ($total === null || $total === 0 || $end >= $total) {
                return null;
            }
        }

        $difference = $end - $start;

        return $difference === PHP_INT_MAX
            ? null
            : ['start' => $start, 'length' => $difference + 1];
    }

    private function parseUnsignedInteger(string $value): ?int
    {
        $normalized = ltrim($value, '0');

        if ($normalized === '') {
            return 0;
        }

        $maximum = (string) PHP_INT_MAX;

        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            return null;
        }

        return (int) $normalized;
    }
}
