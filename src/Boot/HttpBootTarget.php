<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Boot;

use Componenta\App\Boot\Target\HttpBootTargetInterface;
use Componenta\App\RoadRunner\App;

/** @internal */
final readonly class HttpBootTarget implements HttpBootTargetInterface
{
    public function __construct(private App $app) {}

    public function pipe(mixed $middleware, int $priority = 0): void
    {
        $this->app->pipe($middleware, $priority);
    }
}
