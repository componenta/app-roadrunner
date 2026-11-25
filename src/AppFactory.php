<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\AppFactory as DefaultAppFactory;
use Componenta\App\AppFactoryInterface;
use Componenta\App\AppInterface;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;

/** Compatibility entry point; selection is owned by the configured HTTP runtime service. */
final readonly class AppFactory implements AppFactoryInterface
{
    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        return new DefaultAppFactory()->createApp($scope, $container);
    }
}
