<?php

declare(strict_types=1);

use Componenta\App\RoadRunner\Reset\CurrentUserResetter;
use Componenta\DI\Resolver\CurrentUserProvider;

it('clears the shared current-user provider', function (): void {
    $provider = new CurrentUserProvider(new stdClass());
    new CurrentUserResetter($provider)->reset();

    expect($provider->getUser())->toBeNull();
});

it('clears a replacement user installed by a destructor', function (): void {
    $provider = new CurrentUserProvider();
    $provider->setUser(new class($provider) {
        public function __construct(private CurrentUserProvider $provider) {}

        public function __destruct()
        {
            $this->provider->setUser(new stdClass());
        }
    });

    new CurrentUserResetter($provider)->reset();

    expect($provider->getUser())->toBeNull();
});
