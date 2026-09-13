<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

final class ResponseParityFixture
{
    private function __construct() {}

    public static function create(string $path): ResponseInterface
    {
        return match ($path) {
            '/full' => new Response(
                200,
                ['Content-Type' => 'text/plain;charset=UTF-8', 'X-Parity-Case' => 'full'],
                'abcdef',
            ),
            '/status/205' => new Response(
                205,
                ['Content-Type' => 'text/plain;charset=UTF-8', 'X-Parity-Case' => 'status-205'],
                'abcdef',
            ),
            '/range/206' => new Response(
                206,
                [
                    'Content-Type' => 'text/plain;charset=UTF-8',
                    'Content-Range' => 'bytes 2-4/6',
                    'X-Parity-Case' => 'range-206',
                ],
                'cde',
            ),
            '/range/416' => new Response(416, ['Content-Range' => 'bytes */20'], 'range unavailable'),
            '/stream/non-seekable' => new Response(
                200,
                ['Content-Type' => 'text/plain;charset=UTF-8', 'X-Parity-Case' => 'non-seekable'],
                new NonSeekableStream(Stream::create('abcdef')),
            ),
            default => throw new InvalidArgumentException(sprintf('Unknown parity fixture path %s.', $path)),
        };
    }
}
