<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\App;
use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Tests\Fixture\CallableMiddleware;
use Componenta\App\RoadRunner\Tests\Fixture\CountingResetter;
use Componenta\App\RoadRunner\Tests\Fixture\FakePipelineFactory;
use Componenta\App\RoadRunner\Tests\Fixture\FakePsr7Worker;
use Componenta\App\RoadRunner\Tests\Fixture\PassthroughMiddlewareResolver;
use Componenta\App\RoadRunner\Tests\Fixture\ThrowingSizeStream;
use Componenta\Http\Middleware\MiddlewareFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;

function makeRoadRunnerApp(
    FakePsr7Worker $worker,
    FakePipelineFactory $pipelineFactory,
    \Componenta\App\RoadRunner\Reset\RequestResetterInterface $resetter,
): App {
    $psr17 = new Psr17Factory();

    return new App(
        $worker,
        $psr17,
        $pipelineFactory,
        new MiddlewareFactory(new PassthroughMiddlewareResolver()),
        new ResponsePreparer($psr17),
        $resetter,
    );
}

it('handles multiple requests with one pipeline and resets after every request', function (): void {
    $worker = new FakePsr7Worker([
        new ServerRequest('GET', '/first'),
        new ServerRequest('GET', '/second'),
        null,
    ]);
    $resetter = new CountingResetter();
    $pipelineFactory = new FakePipelineFactory(
        static fn ($request): Response => new Response(200, [], $request->getUri()->getPath()),
    );

    $app = makeRoadRunnerApp($worker, $pipelineFactory, $resetter);

    expect($app->run())->toBeNull()
        ->and($resetter->resetCalls)->toBe(2)
        ->and($pipelineFactory->createCalls)->toBe(1)
        ->and(array_map(static fn ($response): string => (string) $response->getBody(), $worker->responses))
        ->toBe(['/first', '/second']);
});

it('sorts middleware by priority while preserving insertion order', function (): void {
    $worker = new FakePsr7Worker([null]);
    $pipelineFactory = new FakePipelineFactory(static fn (): Response => new Response());
    $app = makeRoadRunnerApp($worker, $pipelineFactory, new CountingResetter());

    $low = new CallableMiddleware(static fn ($request, $handler) => $handler->handle($request));
    $highFirst = new CallableMiddleware(static fn ($request, $handler) => $handler->handle($request));
    $highSecond = new CallableMiddleware(static fn ($request, $handler) => $handler->handle($request));

    $app->pipe($low, 0);
    $app->pipe($highFirst, 100);
    $app->pipe($highSecond, 100);
    $app->run();

    expect($pipelineFactory->middlewares)->toBe([$highFirst, $highSecond, $low]);
});

it('returns a generic 500 and still resets request state', function (): void {
    $worker = new FakePsr7Worker([new ServerRequest('GET', '/broken'), null]);
    $resetter = new CountingResetter();
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static fn (): never => throw new RuntimeException('secret failure')),
        $resetter,
    );

    expect($app->run())->toBeNull()
        ->and($worker->responses)->toHaveCount(1)
        ->and($worker->responses[0]->getStatusCode())->toBe(500)
        ->and((string) $worker->responses[0]->getBody())->toBe('')
        ->and($resetter->resetCalls)->toBe(1);
});

it('returns 400 after malformed input and resets state', function (): void {
    $worker = new FakePsr7Worker([new JsonException('bad payload'), null]);
    $resetter = new CountingResetter();
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static fn (): Response => new Response()),
        $resetter,
    );

    expect($app->run())->toBeNull()
        ->and($worker->responses)->toHaveCount(1)
        ->and($worker->responses[0]->getStatusCode())->toBe(400)
        ->and($resetter->resetCalls)->toBe(1);
});

it('terminates a dirty worker after reset failure', function (): void {
    $worker = new FakePsr7Worker([new ServerRequest('GET', '/'), null]);
    $resetter = new CountingResetter();
    $resetter->failReset = true;
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static fn (): Response => new Response()),
        $resetter,
    );

    expect($app->run())->toBe(1)
        ->and($worker->binaryWorker()->stopCalls)->toBe(0);
});

it('terminates after a response transport failure but still resets', function (): void {
    $worker = new FakePsr7Worker([new ServerRequest('GET', '/')]);
    $worker->failResponse = true;
    $resetter = new CountingResetter();
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static fn (): Response => new Response()),
        $resetter,
    );

    expect($app->run())->toBe(1)
        ->and($resetter->resetCalls)->toBe(1);
});

it('falls back to a generic 500 after response preparation fails', function (): void {
    $resource = fopen('php://temp', 'r+');
    fwrite($resource, 'broken');
    rewind($resource);
    $brokenBody = new ThrowingSizeStream(Nyholm\Psr7\Stream::create($resource));

    $calls = 0;
    $worker = new FakePsr7Worker([
        new ServerRequest('GET', '/broken-response'),
        new ServerRequest('GET', '/healthy-response'),
        null,
    ]);
    $resetter = new CountingResetter();
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static function () use (&$calls, $brokenBody): Response {
            ++$calls;

            return $calls === 1
                ? new Response(200, [], $brokenBody)
                : new Response(200, [], 'healthy');
        }),
        $resetter,
    );

    expect($app->run())->toBeNull()
        ->and($worker->responses[0]->getStatusCode())->toBe(500)
        ->and((string) $worker->responses[1]->getBody())->toBe('healthy')
        ->and($resetter->resetCalls)->toBe(2);
});

it('closes output buffers opened by a request', function (): void {
    $baseline = ob_get_level();
    $worker = new FakePsr7Worker([new ServerRequest('GET', '/'), null]);
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static function (): Response {
            ob_start();
            echo 'request output';

            return new Response();
        }),
        new CountingResetter(),
    );

    try {
        expect($app->run())->toBeNull()
            ->and(ob_get_level())->toBe($baseline);
    } finally {
        while (ob_get_level() > $baseline) {
            ob_end_clean();
        }
    }
});

it('does not respond or reuse the worker after an unexpected receive failure', function (): void {
    $worker = new FakePsr7Worker([
        new RuntimeException('relay read failed'),
        new ServerRequest('GET', '/must-not-run'),
        null,
    ]);
    $resetter = new CountingResetter();
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static fn (): never => throw new LogicException('unexpected request')),
        $resetter,
    );

    expect($app->run())->toBe(1)
        ->and($worker->responses)->toBe([])
        ->and($resetter->resetCalls)->toBe(1);
});

it('continues after invalid request data when the response and cleanup succeed', function (): void {
    $worker = new FakePsr7Worker([
        new InvalidArgumentException('invalid request'),
        new ServerRequest('GET', '/next'),
        null,
    ]);
    $resetter = new CountingResetter();
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static fn (): Response => new Response(200, [], 'ok')),
        $resetter,
    );

    expect($app->run())->toBeNull()
        ->and(array_map(static fn ($response): int => $response->getStatusCode(), $worker->responses))
        ->toBe([400, 200])
        ->and($resetter->resetCalls)->toBe(2);
});

it('discards a worker whose persistent output buffer has been replaced', function (): void {
    $baseline = ob_get_level();
    ob_start();
    $worker = new FakePsr7Worker([new ServerRequest('GET', '/'), null]);
    $app = makeRoadRunnerApp(
        $worker,
        new FakePipelineFactory(static function (): Response {
            ob_end_clean();
            ob_start(static fn (string $chunk): string => $chunk);

            return new Response();
        }),
        new CountingResetter(),
    );

    try {
        expect($app->run())->toBe(1);
    } finally {
        while (ob_get_level() > $baseline) {
            ob_end_clean();
        }
    }
});

it('reports each cleanup failure before discarding the worker', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rr-log-');
    $previous = ini_get('error_log');
    ini_set('error_log', $path);

    try {
        $resetter = new \Componenta\App\RoadRunner\Reset\CompositeRequestResetter([
            new class implements \Componenta\App\RoadRunner\Reset\RequestResetterInterface {
                public function reset(): void { throw new RuntimeException('first-cleanup-failure'); }
            },
            new class implements \Componenta\App\RoadRunner\Reset\RequestResetterInterface {
                public function reset(): void { throw new RuntimeException('second-cleanup-failure'); }
            },
        ]);
        $app = makeRoadRunnerApp(
            new FakePsr7Worker([new ServerRequest('GET', '/'), null]),
            new FakePipelineFactory(static fn (): Response => new Response()),
            $resetter,
        );

        expect($app->run())->toBe(1)
            ->and(file_get_contents($path))->toContain('first-cleanup-failure', 'second-cleanup-failure');
    } finally {
        ini_set('error_log', (string) $previous);
        unlink($path);
    }
});
