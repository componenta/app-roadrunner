<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Reset;

use InvalidArgumentException;
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

    /** @param array<array-key, mixed> $failures */
    public static function fromFailures(array $failures): self
    {
        if ($failures === [] || !array_is_list($failures)) {
            throw new InvalidArgumentException('Reset failures must be a non-empty list.');
        }

        foreach ($failures as $failure) {
            if (!$failure instanceof Throwable) {
                throw new InvalidArgumentException('Every reset failure must be a Throwable.');
            }
        }

        return new self($failures);
    }
}
