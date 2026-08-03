<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Reset\RequestResetterFactory;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use Componenta\App\RoadRunner\Tests\Fixture\CountingResetter;
use Componenta\App\RoadRunner\Tests\Fixture\ServiceContainer;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;

it('builds the configured reset chain and clears the Componenta current user last', function (): void {
    $provider = new CurrentUserProvider(new stdClass());
    $sawCurrentUser = false;
    $resetter = new class($provider, $sawCurrentUser) implements RequestResetterInterface {
        public int $calls = 0;

        public function __construct(
            private CurrentUserProvider $provider,
            private bool &$sawCurrentUser,
        ) {}

        public function reset(): void
        {
            ++$this->calls;
            $this->sawCurrentUser = $this->provider->getUser() !== null;
        }
    };
    $container = new ContainerValue(
        new ServiceContainer([
            'application.resetter' => $resetter,
            CurrentUserProviderInterface::class => $provider,
        ]),
        new Config([
            'roadrunner' => [
                'resetters' => ['application.resetter', 'application.resetter'],
            ],
        ]),
    );

    (new RequestResetterFactory())($container)->reset();

    expect($resetter->calls)->toBe(1)
        ->and($sawCurrentUser)->toBeTrue()
        ->and($provider->getUser())->toBeNull();
});

it('rejects invalid resetter configuration', function (array $resetters): void {
    $container = new ContainerValue(
        new ServiceContainer([]),
        new Config(['roadrunner' => ['resetters' => $resetters]]),
    );

    expect(fn () => (new RequestResetterFactory())($container))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'non-string id' => [[42]],
    'empty id' => [['  ']],
    'aggregate recursion' => [[RequestResetterInterface::class]],
]);

it('rejects a configured service with the wrong contract', function (): void {
    $container = new ContainerValue(
        new ServiceContainer(['invalid' => new stdClass()]),
        new Config(['roadrunner' => ['resetters' => ['invalid']]]),
    );

    expect(fn () => (new RequestResetterFactory())($container))
        ->toThrow(Componenta\Config\Exception\InvalidContainerValueException::class);
});
