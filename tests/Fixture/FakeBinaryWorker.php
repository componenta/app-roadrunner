<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Spiral\RoadRunner\Payload;
use Spiral\RoadRunner\WorkerInterface;

final class FakeBinaryWorker implements WorkerInterface
{
    public int $stopCalls = 0;
    /** @var list<Payload> */
    public array $responses = [];
    /** @var list<string> */
    public array $errors = [];

    public function waitPayload(): ?Payload { return null; }
    public function respond(Payload $payload): void { $this->responses[] = $payload; }
    public function error(string $error): void { $this->errors[] = $error; }
    public function stop(): void { ++$this->stopCalls; }
    public function hasPayload(?string $class = null): bool { return false; }
    public function getPayload(?string $class = null): ?Payload { return null; }
}
