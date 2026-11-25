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
            $request->getMethod() === 'HEAD',
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
            if ($response->hasHeader('Content-Length')) {
                $response = $this->normalizeContentLength($response);
            }

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
        if ($response->hasHeader('Content-Length')) {
            // It describes the corresponding GET representation, which is not
            // necessarily present in the HEAD response body.
            $response = $this->normalizeContentLength($response);
        } elseif (!$response->hasHeader('Transfer-Encoding')) {
            $body = $response->getBody();

            // Do not consume a non-seekable stream to infer optional metadata.
            if ($body->isSeekable() && ($size = $body->getSize()) !== null) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }
        }

        return $response->withBody($this->streamFactory->createStream());
    }

    private function prepareFull(ResponseInterface $response): ResponseInterface
    {
        $body = $response->getBody();

        if (!$body->isReadable()) {
            throw new InvalidArgumentException('RoadRunner response body must be readable.');
        }

        $hasLength = $response->hasHeader('Content-Length');
        if ($hasLength) {
            $response = $this->normalizeContentLength($response);
        }

        $seekable = $body->isSeekable();
        $size = $seekable ? $body->getSize() : null;

        // A declared length must describe the actual transmitted bytes.
        // Only materialize an unknown-size seekable stream if it declares
        // a length; otherwise preserve streaming without a known length.
        if (!$seekable || ($size === null && $hasLength)) {
            if ($seekable) {
                $body->rewind();
            }

            [$body, $size] = $this->copyToSeekableStream($body);
            $response = $response->withBody($body);
        }

        if ($hasLength) {
            if ($response->getHeaderLine('Content-Length') !== (string) $size) {
                throw new InvalidArgumentException('RoadRunner response Content-Length does not match the body size.');
            }

            return $response;
        }

        if ($size !== null && !$response->hasHeader('Transfer-Encoding')) {
            $response = $response->withHeader('Content-Length', (string) $size);
        }

        return $response;
    }

    private function normalizeContentLength(ResponseInterface $response): ResponseInterface
    {
        $length = null;
        foreach (explode(',', $response->getHeaderLine('Content-Length')) as $value) {
            $value = trim($value, " \t");
            if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
                throw new InvalidArgumentException('Invalid RoadRunner response Content-Length.');
            }

            $canonical = ltrim($value, '0');
            $canonical = $canonical === '' ? '0' : $canonical;

            if ($length !== null && $length !== $canonical) {
                throw new InvalidArgumentException('Conflicting RoadRunner response Content-Length values.');
            }

            $length = $canonical;
        }

        return $response->withHeader('Content-Length', $length);
    }

    /** @return array{StreamInterface, int} */
    private function copyToSeekableStream(StreamInterface $source): array
    {
        $target = $this->streamFactory->createStream();
        $size = 0;

        while (!$source->eof()) {
            $chunk = $source->read(self::COPY_CHUNK_SIZE);

            if ($chunk === '') {
                if (!$this->isAtEnd($source)) {
                    throw new RuntimeException('RoadRunner response stream made no progress before EOF.');
                }

                break;
            }

            $this->writeAll($target, $chunk);
            $size += strlen($chunk);
        }

        $target->rewind();

        return [$target, $size];
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
