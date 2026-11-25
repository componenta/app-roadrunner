<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Tests\Fixture\CallableMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

$state = $context->container->get(SmokeRequestState::class, SmokeRequestState::class);
$app->pipe(new CallableMiddleware(static function (ServerRequestInterface $request) use ($state): Response {
    $headers = ['X-Worker-Pid' => (string) getmypid()];
    $path = $request->getUri()->getPath();

    if ($path === '/state') {
        $previous = $state->identity;
        $state->identity = $request->getHeaderLine('X-Identity');

        return new Response(200, $headers, json_encode([
            'previous' => $previous,
            'identity' => $state->identity,
            'server' => $_SERVER['HTTP_X_IDENTITY'] ?? null,
            'memory' => memory_get_usage(true),
        ], JSON_THROW_ON_ERROR));
    }

    if ($path === '/failure') {
        $state->identity = 'must-be-cleared';
        throw new RuntimeException('private failure');
    }

    if ($path === '/reset-failure') {
        $state->fail = true;
        return new Response(200, $headers, 'discard');
    }

    if ($path === '/output') {
        echo 'side-effect-output';
        ob_start();
        echo 'request-buffer-output';
        return new Response(200, $headers, 'clean');
    }

    if ($path === '/upload') {
        $file = $request->getUploadedFiles()['document'];
        return new Response(200, $headers, $file->getStream()->getContents());
    }

    return match ($path) {
        '/full' => new Response(200, $headers, 'abcdef'),
        '/partial' => new Response(206, $headers + ['Content-Range' => 'bytes 10-12/20'], 'abc'),
        '/no-content' => new Response(204, $headers, 'ignored'),
        '/not-modified' => new Response(304, $headers + ['ETag' => '"v1"', 'Content-Length' => '6'], 'ignored'),
        '/large' => new Response(200, $headers, Stream::create(str_repeat('0123456789abcdef', 65536))),
        default => new Response(404, $headers),
    };
}));
