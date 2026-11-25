<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Boot;

use Componenta\App\AppInterface;
use Componenta\App\Boot\BootTargetAdapterInterface;
use Componenta\App\RoadRunner\App;
use Componenta\App\Scope;
use Componenta\Scope\ScopeInterface;

/** @internal Decorates the standard HTTP adapter without replacing other scopes. */
final readonly class HttpBootTargetAdapter implements BootTargetAdapterInterface
{
    public function __construct(private BootTargetAdapterInterface $fallback) {}

    public function supports(ScopeInterface $scope): bool
    {
        return $this->fallback->supports($scope);
    }

    public function create(AppInterface $app, ScopeInterface $scope): object
    {
        if ($scope->matches(Scope::HTTP) && $app instanceof App) {
            return new HttpBootTarget($app);
        }

        return $this->fallback->create($app, $scope);
    }
}
