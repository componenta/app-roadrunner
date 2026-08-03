<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Componenta\Http\Middleware\Resolver\MiddlewareResolverInterface;
use Psr\Http\Server\MiddlewareInterface;

final readonly class PassthroughMiddlewareResolver implements MiddlewareResolverInterface
{
    public function resolve(mixed $middleware): ?MiddlewareInterface
    {
        return $middleware instanceof MiddlewareInterface ? $middleware : null;
    }
}
