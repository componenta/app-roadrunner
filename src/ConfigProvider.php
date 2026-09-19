<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

use Componenta\App\AppAdapterInterface;
use Componenta\App\Server\HttpAppAdapter as SapiHttpAppAdapter;
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

    protected function getDelegators(): array
    {
        return [
            SapiHttpAppAdapter::class => [
                static fn (AppAdapterInterface $inner): AppAdapterInterface => new HttpAppAdapter($inner),
            ],
        ];
    }

    /**
     * @return array{roadrunner: array{http: array{chunk_size: int}, worker: array{intercept_side_effects: bool}, resetters: list<string>}}
     */
    protected function getConfig(): array
    {
        return [
            ConfigKey::ROOT => [
                'http' => ['chunk_size' => 0],
                'worker' => ['intercept_side_effects' => true],
                'resetters' => [],
            ],
        ];
    }
}
