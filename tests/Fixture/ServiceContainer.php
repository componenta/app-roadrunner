<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Psr\Container\ContainerInterface;
use RuntimeException;

final readonly class ServiceContainer implements ContainerInterface
{
    /** @param array<string, mixed> $services */
    public function __construct(private array $services) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
