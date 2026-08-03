<?php

declare(strict_types=1);

use Componenta\App\AppFactory as DefaultAppFactory;
use Componenta\App\AppFactoryInterface;
use Componenta\App\Boot\HttpBootTargetAdapter;
use Componenta\App\Boot\Target\HttpBootTargetInterface;
use Componenta\App\ConfigKey as AppConfigKey;
use Componenta\App\ConfigProvider as AppConfigProvider;
use Componenta\App\RoadRunner\App as RoadRunnerApp;
use Componenta\App\RoadRunner\AppFactory as RoadRunnerAppFactory;
use Componenta\App\RoadRunner\ConfigProvider;
use Componenta\App\RoadRunner\Http\ResponsePreparer;
use Componenta\App\RoadRunner\Reset\RequestResetterFactory;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use Componenta\App\RoadRunner\Tests\Fixture\CountingResetter;
use Componenta\App\RoadRunner\Tests\Fixture\FakePipelineFactory;
use Componenta\App\RoadRunner\Tests\Fixture\FakePsr7Worker;
use Componenta\App\RoadRunner\Tests\Fixture\PassthroughMiddlewareResolver;
use Componenta\App\RoadRunner\Tests\Fixture\ServiceContainer;
use Componenta\App\RoadRunner\Worker\Psr7WorkerFactory;
use Componenta\App\Scope;
use Componenta\App\Server\App as SapiApp;
use Componenta\App\Server\ConfigProvider as HttpConfigProvider;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\ContainerBuilder;
use Componenta\Http\EmitterInterface;
use Componenta\Http\Middleware\MiddlewareFactory;
use Componenta\Http\Middleware\PipelineFactoryInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7Server\ServerRequestCreatorInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;

use function Componenta\Config\config_merge;

function roadRunnerAppFactoryContainer(): ContainerValue
{
    $psr17 = new Psr17Factory();
    $pipelineFactory = new FakePipelineFactory(static fn (): Response => new Response());
    $middlewareFactory = new MiddlewareFactory(new PassthroughMiddlewareResolver());
    $requestCreator = new class implements ServerRequestCreatorInterface {
        public function fromGlobals(): ServerRequestInterface
        {
            return new ServerRequest('GET', '/');
        }

        public function fromArrays(
            array $server,
            array $headers = [],
            array $cookie = [],
            array $get = [],
            ?array $post = null,
            array $files = [],
            $body = null,
        ): ServerRequestInterface {
            return new ServerRequest('GET', '/');
        }

        public static function getHeadersFromServer(array $server): array
        {
            return [];
        }
    };
    $emitter = new class implements EmitterInterface {
        public function emit(ResponseInterface $response): void {}
    };
    $sapiApp = new SapiApp($requestCreator, $emitter, $pipelineFactory, $middlewareFactory);

    $entries = [
        PSR7WorkerInterface::class => new FakePsr7Worker([null]),
        ResponseFactoryInterface::class => $psr17,
        PipelineFactoryInterface::class => $pipelineFactory,
        MiddlewareFactory::class => $middlewareFactory,
        ResponsePreparer::class => new ResponsePreparer($psr17),
        RequestResetterInterface::class => new CountingResetter(),
        ServerRequestCreatorInterface::class => $requestCreator,
        EmitterInterface::class => $emitter,
        SapiApp::class => $sapiApp,
    ];

    $config = [
        AppConfigKey::APP_BY_SCOPE => [
            Scope::HTTP->value => SapiApp::class,
        ],
    ];

    return new ContainerValue(
        new ServiceContainer($entries),
        new Config($config),
    );
}

it('declares each integration service with its actual creation strategy', function (): void {
    $config = (new ConfigProvider())();
    $dependencies = $config['dependencies'];

    expect($dependencies['aliases'][DefaultAppFactory::class])->toBe(RoadRunnerAppFactory::class)
        ->and($dependencies['aliases'])->not->toHaveKey(HttpBootTargetAdapter::class)
        ->and($dependencies['invokables'])->toBe([
            RoadRunnerAppFactory::class,
            Psr7WorkerFactory::class,
            RequestResetterFactory::class,
        ])
        ->and($dependencies['factories'])->toHaveKeys([
            PSR7WorkerInterface::class,
            RequestResetterInterface::class,
            ResponsePreparer::class,
        ])
        ->and($dependencies)->not->toHaveKey('autowires')
        ->and($config['roadrunner']['http']['chunk_size'])->toBe(0);
});

it('keeps factory aliases regardless of provider merge order', function (): void {
    $app = (new AppConfigProvider())();
    $http = (new HttpConfigProvider())();
    $roadRunner = (new ConfigProvider())();

    foreach ([
        config_merge(config_merge($app, $http), $roadRunner),
        config_merge(config_merge($roadRunner, $app), $http),
    ] as $config) {
        $container = ContainerBuilder::configure(new Config($config))->build();

        expect($container->get(AppFactoryInterface::class))->toBeInstanceOf(RoadRunnerAppFactory::class)
            ->and($container->get(HttpBootTargetAdapter::class))->toBeInstanceOf(HttpBootTargetAdapter::class);
    }
});

it('selects RoadRunner only inside an HTTP worker and uses the app as boot target', function (): void {
    $hadMode = array_key_exists('RR_MODE', $_SERVER);
    $mode = $_SERVER['RR_MODE'] ?? null;
    $_SERVER['RR_MODE'] = 'http';

    try {
        $app = (new RoadRunnerAppFactory())->createApp(Scope::HTTP, roadRunnerAppFactoryContainer());

        expect($app)->toBeInstanceOf(RoadRunnerApp::class)
            ->and((new HttpBootTargetAdapter())->create($app, Scope::HTTP))->toBe($app);
    } finally {
        if ($hadMode) {
            $_SERVER['RR_MODE'] = $mode;
        } else {
            unset($_SERVER['RR_MODE']);
        }
    }
});

it('preserves the standard SAPI HTTP app outside RoadRunner', function (): void {
    $hadMode = array_key_exists('RR_MODE', $_SERVER);
    $mode = $_SERVER['RR_MODE'] ?? null;
    $_SERVER['RR_MODE'] = 'sapi';

    try {
        $app = (new RoadRunnerAppFactory())->createApp(Scope::HTTP, roadRunnerAppFactoryContainer());
        $target = (new HttpBootTargetAdapter())->create($app, Scope::HTTP);

        expect($app)->toBeInstanceOf(SapiApp::class)
            ->and($target)->toBeInstanceOf(HttpBootTargetInterface::class);
    } finally {
        if ($hadMode) {
            $_SERVER['RR_MODE'] = $mode;
        } else {
            unset($_SERVER['RR_MODE']);
        }
    }
});
