<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\Server\HttpApplicationInterface;
use Componenta\App\Exception\MiddlewarePipeException;
use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use Componenta\Config\ContainerValue;
use Componenta\Http\Middleware\Exception\MiddlewareResolutionExceptionInterface;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\PipelineFactoryInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Spiral\RoadRunner\Http\GlobalState;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use RuntimeException;
use Throwable;

final class App implements HttpApplicationInterface
{
    /** @var list<array{middleware: MiddlewareInterface, priority: int, order: int}> */
    private array $middlewares = [];

    private int $middlewareOrder = 0;

    public function __construct(
        private readonly PSR7WorkerInterface $worker,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly PipelineFactoryInterface $pipelineFactory,
        private readonly MiddlewareFactory $middlewareFactory,
        private readonly ResponsePreparer $responsePreparer,
        private readonly RequestResetterInterface $resetter,
    ) {}

    public function run(): ?int
    {
        $pipeline = $this->pipelineFactory->createMiddlewarePipeline($this->sortedMiddlewares());

        $outputBufferLevel = ob_get_level();

        // GlobalState is loaded while the container is assembled. Refresh its
        // baseline after all Componenta bootloaders have finished.
        GlobalState::cacheServerVars();

        while (true) {
            try {
                $request = $this->worker->waitRequest();
            } catch (Throwable $exception) {
                $this->report($exception);
                unset($exception);

                $responded = $this->respondWithStatus(400, 'Bad Request');
                $reset = $this->resetSafely($outputBufferLevel);

                if (!$responded || !$reset) {
                    return 1;
                }

                continue;
            }

            if ($request === null) {
                return null;
            }

            $response = null;
            $terminate = false;

            try {
                try {
                    $response = $pipeline->handle($request);
                } catch (Throwable $exception) {
                    $this->report($exception);
                    unset($exception);
                    $response = $this->createStatusResponse(500, 'Internal Server Error');
                }

                if ($response === null || !$this->prepareAndRespondSafely($request, $response)) {
                    $terminate = true;
                }
            } finally {
                unset($request, $response);

                if (!$this->resetSafely($outputBufferLevel)) {
                    $terminate = true;
                }
            }

            if ($terminate) {
                // A non-zero process exit lets RoadRunner discard a worker
                // whose transport or request state can no longer be trusted.
                return 1;
            }
        }
    }

    /** @throws MiddlewareResolutionExceptionInterface */
    public function pipe(mixed $middleware, int $priority = 0): void
    {
        try {
            $this->middlewares[] = [
                'middleware' => $this->middlewareFactory->createMiddleware($middleware),
                'priority' => $priority,
                'order' => $this->middlewareOrder++,
            ];
        } catch (MiddlewareResolutionExceptionInterface $exception) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

            throw new MiddlewarePipeException(
                $exception->middleware,
                $caller['file'] ?? 'unknown',
                $caller['line'] ?? 0,
                $exception,
            );
        }
    }

    /**
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public static function createFromContainer(ContainerValue $container): self
    {
        return new self(
            $container->get(PSR7WorkerInterface::class, PSR7WorkerInterface::class),
            $container->get(ResponseFactoryInterface::class, ResponseFactoryInterface::class),
            $container->get(PipelineFactoryInterface::class, PipelineFactoryInterface::class),
            $container->get(MiddlewareFactory::class, MiddlewareFactory::class),
            $container->get(ResponsePreparer::class, ResponsePreparer::class),
            $container->get(RequestResetterInterface::class, RequestResetterInterface::class),
        );
    }

    private function respondWithStatus(int $status, string $reasonPhrase): bool
    {
        $response = $this->createStatusResponse($status, $reasonPhrase);

        return $response !== null && $this->respondSafely($response);
    }

    private function createStatusResponse(int $status, string $reasonPhrase): ?ResponseInterface
    {
        try {
            return $this->responseFactory->createResponse($status, $reasonPhrase);
        } catch (Throwable $exception) {
            $this->report($exception);
            return null;
        }
    }

    private function prepareAndRespondSafely(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): bool {
        try {
            $response = $this->responsePreparer->prepare($request, $response);
        } catch (Throwable $exception) {
            $this->report($exception);
            unset($exception);

            // Response preparation is application/output failure, not proof
            // that the Goridge transport is corrupt. Send a minimal generic
            // response directly and keep the worker only when cleanup succeeds.
            return $this->respondWithStatus(500, 'Internal Server Error');
        }

        return $this->respondSafely($response);
    }

    private function respondSafely(ResponseInterface $response): bool
    {
        try {
            $this->worker->respond($response);
            return true;
        } catch (Throwable $exception) {
            $this->report($exception);
            return false;
        }
    }

    private function resetSafely(int $outputBufferLevel): bool
    {
        $clean = true;

        try {
            $this->resetter->reset();
        } catch (Throwable $exception) {
            $this->report($exception);
            $clean = false;
        }

        try {
            while (ob_get_level() > $outputBufferLevel) {
                if (!ob_end_clean()) {
                    throw new RuntimeException('Unable to close a request output buffer.');
                }
            }

            if (ob_get_level() < $outputBufferLevel) {
                throw new RuntimeException('A request closed a persistent worker output buffer.');
            }
        } catch (Throwable $exception) {
            $this->report($exception);
            $clean = false;
        }

        return $clean;
    }

    private function report(Throwable $exception): void
    {
        try {
            error_log('[componenta/app-roadrunner] ' . $exception);
        } catch (Throwable) {
            // Reporting must not replace the original failure or skip cleanup.
        }
    }

    /** @return list<MiddlewareInterface> */
    private function sortedMiddlewares(): array
    {
        $entries = $this->middlewares;

        usort(
            $entries,
            static fn (array $left, array $right): int => $right['priority'] <=> $left['priority']
                ?: $left['order'] <=> $right['order'],
        );

        /** @var list<MiddlewareInterface> $middlewares */
        $middlewares = array_column($entries, 'middleware');

        return $middlewares;
    }
}
