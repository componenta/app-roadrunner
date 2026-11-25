<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\AppInterface;
use Componenta\App\Boot\Target\HttpBootTargetInterface;
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
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class App implements AppInterface, HttpBootTargetInterface
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

        $outputBuffers = $this->outputBufferSignature();

        // GlobalState is loaded while the container is assembled. Refresh its
        // baseline after all Componenta bootloaders have finished.
        GlobalState::cacheServerVars();

        while (true) {
            try {
                $request = $this->worker->waitRequest();
            } catch (JsonException | InvalidArgumentException $exception) {
                // Request decoding or PSR-7 validation failed after receipt.
                $this->report($exception);
                unset($exception);

                $responded = $this->respondWithStatus(400, 'Bad Request');
                $reset = $this->resetSafely($outputBuffers);

                if (!$responded || !$reset) {
                    return 1;
                }

                continue;
            } catch (Throwable $exception) {
                // A failed receive may mean that there is no pending request
                // or the relay is no longer synchronized. Do not write a frame.
                $this->report($exception);
                unset($exception);
                $this->resetSafely($outputBuffers);

                return 1;
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

                if (!$this->resetSafely($outputBuffers)) {
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

    /** @param list<array{name: string, type: int, flags: int, level: int, chunk_size: int}> $outputBuffers */
    private function resetSafely(array $outputBuffers): bool
    {
        $outputBufferLevel = count($outputBuffers);
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

            if ($this->outputBufferSignature() !== $outputBuffers) {
                throw new RuntimeException('A request changed a persistent worker output buffer.');
            }
        } catch (Throwable $exception) {
            $this->report($exception);
            $clean = false;
        }

        return $clean;
    }

    /**
     * Buffer contents and runtime flags may legitimately change on writes.
     * The signature detects structural changes, not replacement by an identical
     * handler. The entry point independently protects the transport guard.
     *
     * @return list<array{name: string, type: int, flags: int, level: int, chunk_size: int}>
     */
    private function outputBufferSignature(): array
    {
        return array_map(
            static fn (array $buffer): array => [
                'name' => $buffer['name'],
                'type' => $buffer['type'],
                'flags' => $buffer['flags'] & PHP_OUTPUT_HANDLER_STDFLAGS,
                'level' => $buffer['level'],
                'chunk_size' => $buffer['chunk_size'],
            ],
            ob_get_status(true),
        );
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
