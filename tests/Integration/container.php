<?php

declare(strict_types=1);

use Componenta\App\ConfigProvider as AppConfigProvider;
use Componenta\App\RoadRunner\ConfigProvider as RoadRunnerConfigProvider;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use Componenta\App\RoadRunner\Tests\Fixture\PassthroughMiddlewareResolver;
use Componenta\App\Server\ConfigProvider as HttpConfigProvider;
use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\PipelineFactory;
use Componenta\Http\Middleware\PipelineFactoryInterface;
use Componenta\Stdlib\PathResolver;
use Componenta\Stdlib\PathResolverInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

final class SmokeRequestState implements RequestResetterInterface
{
    public ?string $identity = null;
    public bool $fail = false;

    public function reset(): void
    {
        $this->identity = null;
        if ($this->fail) {
            throw new RuntimeException('intentional cleanup failure');
        }
    }
}

$psr17 = new Psr17Factory();
$services = [
    ResponseFactoryInterface::class => $psr17,
    ServerRequestFactoryInterface::class => $psr17,
    StreamFactoryInterface::class => $psr17,
    UploadedFileFactoryInterface::class => $psr17,
    PipelineFactoryInterface::class => new PipelineFactory(),
    MiddlewareFactory::class => new MiddlewareFactory(new PassthroughMiddlewareResolver()),
    PathResolverInterface::class => new PathResolver(dirname(__DIR__)),
    SmokeRequestState::class => new SmokeRequestState(),
];
$composition = (new ConfigFactory())->create(
    new Environment([]),
    new AppConfigProvider(),
    new HttpConfigProvider(),
    new RoadRunnerConfigProvider(),
    static fn (): array => [
        'dependencies' => [
            'factories' => array_map(static fn (object $service): Closure => static fn (): object => $service, $services),
        ],
        'roadrunner' => [
            'http' => ['chunk_size' => (int) getenv('SMOKE_CHUNK_SIZE')],
            'resetters' => [SmokeRequestState::class],
        ],
    ],
);

return (new ContainerFactory())->create($composition->config, $composition->dependencies);
