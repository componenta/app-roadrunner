<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class CallableMiddleware implements MiddlewareInterface
{
    private mixed $callback;

    /** @param callable(ServerRequestInterface, RequestHandlerInterface): ResponseInterface $callback */
    public function __construct(callable $callback) { $this->callback = $callback; }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ($this->callback)($request, $handler);
    }
}
