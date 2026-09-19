<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\AppFactory as DefaultAppFactory;
use Componenta\App\AppFactoryInterface;
use Componenta\App\AppInterface;
use Componenta\App\Scope;
use Componenta\Config\ContainerValue;
use Componenta\Scope\ScopeInterface;

/** Compatibility entry point; ConfigProvider integrates through HttpAppAdapter. */
final readonly class AppFactory implements AppFactoryInterface
{
    public function createApp(ScopeInterface $scope, ContainerValue $container): AppInterface
    {
        if ($scope->matches(Scope::HTTP) && self::isRoadRunnerWorker()) {
            return App::createFromContainer($container);
        }

        return new DefaultAppFactory()->createApp($scope, $container);
    }

    private static function isRoadRunnerWorker(): bool
    {
        $mode = $_SERVER['RR_MODE'] ?? $_ENV['RR_MODE'] ?? getenv('RR_MODE');

        return is_string($mode) && strtolower(trim($mode)) === 'http';
    }
}
