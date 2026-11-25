<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\AppAdapterInterface;
use Componenta\App\AppInterface;
use Componenta\App\Scope;
use Componenta\App\RoadRunner\Worker\WorkerMode;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;

final readonly class HttpAppAdapter implements AppAdapterInterface
{
    public function __construct(private AppAdapterInterface $fallback) {}

    public function supports(ScopeInterface $scope): bool
    {
        return $this->fallback->supports($scope);
    }

    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        if ($scope->matches(Scope::HTTP) && WorkerMode::isHttp()) {
            return App::createFromContainer($container);
        }
        return $this->fallback->createApp($scope, $container);
    }
}
