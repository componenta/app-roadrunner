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
use Componenta\App\Server\HttpAppAdapter;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
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

function roadRunnerAppFactoryServices(): array
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

    return $entries;
}

function roadRunnerAppFactoryContainer(): ContainerValue
{
    $config = [
        AppConfigKey::APP_ADAPTERS => [HttpAppAdapter::class],
    ];

    return new ContainerValue(
        new ServiceContainer([
            ...roadRunnerAppFactoryServices(),
            HttpAppAdapter::class => new HttpAppAdapter(),
        ]),
        new Config($config, new \Componenta\Config\Environment([])),
    );
}

it('declares each integration service with its actual creation strategy', function (): void {
    $config = (new ConfigProvider())();
    $dependencies = $config['dependencies'];

    expect($dependencies['delegators'])->toHaveKey(\Componenta\App\Server\HttpAppAdapter::class)
        ->and($dependencies['aliases'] ?? [])->not->toHaveKey(DefaultAppFactory::class)
        ->and($dependencies['invokables'])->toBe([
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

it('uses the shared app factory regardless of provider merge order', function (): void {
    $app = (new AppConfigProvider())();
    $http = (new HttpConfigProvider())();
    $roadRunner = (new ConfigProvider())();

    foreach ([
        [$app, $http, $roadRunner],
        [$roadRunner, $app, $http],
    ] as $config) {
        $composition = (new \Componenta\Config\ConfigFactory())->create(new \Componenta\Config\Environment([]), ...array_map(static fn (array $value): Closure => static fn (): array => $value, $config));
        $container = (new \Componenta\DI\ContainerFactory())->create($composition->config, $composition->dependencies);

        expect($container->get(AppFactoryInterface::class))->toBeInstanceOf(DefaultAppFactory::class)
            ->and($container->get(HttpBootTargetAdapter::class))->toBeInstanceOf(HttpBootTargetAdapter::class);
    }
});

it('selects the HTTP runtime through DI for both provider orders', function (string $mode): void {
    $hadMode = array_key_exists('RR_MODE', $_SERVER);
    $previous = $_SERVER['RR_MODE'] ?? null;
    $_SERVER['RR_MODE'] = $mode;
    try {
        foreach ([
            [new AppConfigProvider(), new HttpConfigProvider(), new ConfigProvider()],
            [new ConfigProvider(), new AppConfigProvider(), new HttpConfigProvider()],
        ] as $providers) {
            $services = roadRunnerAppFactoryServices();
            $providers[] = static fn (): array => ['dependencies' => ['factories' => array_map(
                static fn (object $entry): Closure => static fn (): object => $entry,
                $services,
            )]];
            $composition = (new \Componenta\Config\ConfigFactory())->create(new \Componenta\Config\Environment([]), ...$providers);
            $container = (new \Componenta\DI\ContainerFactory())->create($composition->config, $composition->dependencies);
            $app = $container->get(AppFactoryInterface::class)->createApp(Scope::HTTP, $container);
            expect($app)->toBeInstanceOf(strtolower(trim($mode)) === 'http' ? RoadRunnerApp::class : SapiApp::class);
            if (strtolower(trim($mode)) !== 'http') { expect($app)->toBe($services[SapiApp::class]); }
            else { expect((new HttpBootTargetAdapter())->create($app, Scope::HTTP))->toBe($app); }
        }
    } finally {
        if ($hadMode) { $_SERVER['RR_MODE'] = $previous; } else { unset($_SERVER['RR_MODE']); }
    }
})->with(['http', ' HTTP ', 'sapi', 'jobs', '']);

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
