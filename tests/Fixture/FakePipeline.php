<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Componenta\Http\Middleware\PipelineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class FakePipeline implements PipelineInterface
{
    private mixed $handler;

    /** @param callable(ServerRequestInterface): ResponseInterface $handler */
    public function __construct(callable $handler) { $this->handler = $handler; }
    public function handle(ServerRequestInterface $request): ResponseInterface { return ($this->handler)($request); }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface { return $this->handle($request); }
    public function pipe(iterable|MiddlewareInterface $middlewares): PipelineInterface { return $this; }
}
