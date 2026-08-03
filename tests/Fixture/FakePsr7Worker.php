<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\RoadRunner\Http\PSR7WorkerInterface;
use Spiral\RoadRunner\WorkerInterface;
use Throwable;

final class FakePsr7Worker implements PSR7WorkerInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    public bool $failResponse = false;

    /** @param list<ServerRequestInterface|Throwable|null> $requests */
    public function __construct(
        private array $requests,
        private readonly FakeBinaryWorker $binaryWorker = new FakeBinaryWorker(),
    ) {}

    public function waitRequest(): ?ServerRequestInterface
    {
        $next = array_shift($this->requests);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function respond(ResponseInterface $response): void
    {
        if ($this->failResponse) {
            throw new \RuntimeException('response transport failed');
        }
        $this->responses[] = $response;
    }

    public function getWorker(): WorkerInterface { return $this->binaryWorker; }
    public function binaryWorker(): FakeBinaryWorker { return $this->binaryWorker; }
}
