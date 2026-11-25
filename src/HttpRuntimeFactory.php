<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\Server\App as SapiApp;
use Componenta\App\Server\HttpApplicationInterface;
use Componenta\Config\ContainerValue;

final readonly class HttpRuntimeFactory
{
    public function __invoke(ContainerValue $container): HttpApplicationInterface
    {
        $mode = $_SERVER['RR_MODE'] ?? $_ENV['RR_MODE'] ?? getenv('RR_MODE');

        if (is_string($mode) && strtolower(trim($mode)) === 'http') {
            return App::createFromContainer($container);
        }

        return $container->get(SapiApp::class, HttpApplicationInterface::class);
    }
}
