<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\Server\ConfigKey as HttpConfigKey;
use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Reset\RequestResetterFactory;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use Componenta\App\RoadRunner\Worker\Psr7WorkerFactory;
use Componenta\Config\ConfigProvider as BaseConfigProvider;
use Componenta\Config\ContainerValue;
use Psr\Http\Message\StreamFactoryInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;

final class ConfigProvider extends BaseConfigProvider
{
    protected function getFactories(): array
    {
        return [
            ConfigKey::HTTP_RUNTIME_SERVICE => HttpRuntimeFactory::class,
            PSR7WorkerInterface::class => Psr7WorkerFactory::class,
            RequestResetterInterface::class => RequestResetterFactory::class,
            ResponsePreparer::class => static fn (ContainerValue $container): ResponsePreparer => new ResponsePreparer(
                $container->get(StreamFactoryInterface::class, StreamFactoryInterface::class),
            ),
        ];
    }

    protected function getInvokables(): array
    {
        return [
            Psr7WorkerFactory::class,
            RequestResetterFactory::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function getConfig(): array
    {
        return [
            HttpConfigKey::RUNTIME => ConfigKey::HTTP_RUNTIME_SERVICE,
            ConfigKey::ROOT => [
                'http' => ['chunk_size' => 0],
                'worker' => ['intercept_side_effects' => true],
                'resetters' => [],
            ],
        ];
    }
}
