<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Reset\RequestResetterFactory;
use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use Componenta\App\RoadRunner\Tests\Fixture\CountingResetter;
use Componenta\App\RoadRunner\Tests\Fixture\ServiceContainer;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;

it('resets each configured service once even when an id is repeated', function (): void {
    $first = new CountingResetter();
    $second = new CountingResetter();
    $container = new ContainerValue(
        new ServiceContainer(['first' => $first, 'second' => $second]),
        new Config(['roadrunner' => ['resetters' => ['first', 'second', 'first']]], new \Componenta\Config\Environment([])),
    );

    (new RequestResetterFactory())($container)->reset();

    expect($first->resetCalls)->toBe(1)->and($second->resetCalls)->toBe(1);
});

it('rejects invalid resetter configuration', function (array $resetters): void {
    $container = new ContainerValue(
        new ServiceContainer([]),
        new Config(['roadrunner' => ['resetters' => $resetters]], new \Componenta\Config\Environment([])),
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
        new Config(['roadrunner' => ['resetters' => ['invalid']]], new \Componenta\Config\Environment([])),
    );

    expect(fn () => (new RequestResetterFactory())($container))
        ->toThrow(Componenta\Config\Exception\InvalidContainerValueException::class);
});

it('resolves a repeated non-shared resetter service id only once', function (): void {
    $services = new class implements \Psr\Container\ContainerInterface {
        /** @var list<CountingResetter> */
        public array $created = [];

        public function get(string $id): mixed
        {
            $resetter = new CountingResetter();
            $this->created[] = $resetter;

            return $resetter;
        }

        public function has(string $id): bool { return $id === 'resetter'; }
    };
    $container = new ContainerValue(
        $services,
        new Config(['roadrunner' => ['resetters' => ['resetter', 'resetter']]], new \Componenta\Config\Environment([])),
    );

    (new RequestResetterFactory())($container)->reset();

    expect($services->created)->toHaveCount(1)
        ->and($services->created[0]->resetCalls)->toBe(1);
});

it('does not invoke the same resetter object twice through aliases', function (): void {
    $resetter = new CountingResetter();
    $container = new ContainerValue(
        new ServiceContainer(['first' => $resetter, 'alias' => $resetter]),
        new Config(['roadrunner' => ['resetters' => ['first', 'alias']]], new \Componenta\Config\Environment([])),
    );

    (new RequestResetterFactory())($container)->reset();

    expect($resetter->resetCalls)->toBe(1);
});
