<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Componenta\Http\Middleware\PipelineFactoryInterface;
use Componenta\Http\Middleware\PipelineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class FakePipelineFactory implements PipelineFactoryInterface
{
    /** @var list<MiddlewareInterface> */
    public array $middlewares = [];
    public int $createCalls = 0;
    private mixed $handler;

    /** @param callable(ServerRequestInterface): ResponseInterface $handler */
    public function __construct(callable $handler) { $this->handler = $handler; }

    public function createMiddlewarePipeline(
        iterable $middlewares = [],
        ?RequestHandlerInterface $fallbackHandler = null,
    ): PipelineInterface {
        ++$this->createCalls;
        $this->middlewares = [...$middlewares];
        return new FakePipeline($this->handler);
    }
}
