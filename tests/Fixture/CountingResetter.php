<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Tests\Fixture;

use Componenta\App\RoadRunner\Reset\RequestResetterInterface;
use RuntimeException;

final class CountingResetter implements RequestResetterInterface
{
    public int $resetCalls = 0;
    public bool $failReset = false;

    public function reset(): void
    {
        ++$this->resetCalls;

        if ($this->failReset) {
            throw new RuntimeException('reset failed');
        }
    }
}
