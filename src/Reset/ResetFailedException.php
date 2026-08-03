<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Reset;

use RuntimeException;
use Throwable;

final class ResetFailedException extends RuntimeException
{
    /** @param non-empty-list<Throwable> $failures */
    private function __construct(
        public readonly array $failures,
    ) {
        parent::__construct(
            sprintf('%d request resetter(s) failed.', count($failures)),
            0,
            $failures[0],
        );
    }

    /** @param non-empty-list<Throwable> $failures */
    public static function fromFailures(array $failures): self
    {
        return new self($failures);
    }
}
