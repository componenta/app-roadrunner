<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Worker;

use Componenta\App\RoadRunner\ConfigKey;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerValue;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\Worker;

final readonly class Psr7WorkerFactory
{
    public function __invoke(ContainerValue $container): PSR7WorkerInterface
    {
        $config = $container->config;
        $chunkSize = $config->int(new ConfigPath(ConfigKey::HTTP_CHUNK_SIZE), 0);

        if ($chunkSize < 0) {
            throw new InvalidArgumentException(sprintf(
                'RoadRunner HTTP chunk size must be greater than or equal to zero, %d given.',
                $chunkSize,
            ));
        }

        $worker = Worker::create(
            interceptSideEffects: $config->bool(
                new ConfigPath(ConfigKey::WORKER_INTERCEPT_SIDE_EFFECTS),
                true,
            ),
        );

        $http = new PSR7Worker(
            $worker,
            $container->get(ServerRequestFactoryInterface::class, ServerRequestFactoryInterface::class),
            $container->get(StreamFactoryInterface::class, StreamFactoryInterface::class),
            $container->get(UploadedFileFactoryInterface::class, UploadedFileFactoryInterface::class),
        );
        return new Psr7WorkerAdapter($http, $chunkSize);
    }
}
