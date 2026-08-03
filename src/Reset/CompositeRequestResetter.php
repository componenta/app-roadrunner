<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Reset;

use Throwable;

final readonly class CompositeRequestResetter implements RequestResetterInterface
{
    /** @param list<RequestResetterInterface> $resetters */
    public function __construct(private array $resetters = []) {}

    public function reset(): void
    {
        $failures = [];

        foreach ($this->resetters as $resetter) {
            try {
                $resetter->reset();
            } catch (Throwable $exception) {
                if ($exception instanceof ResetFailedException) {
                    array_push($failures, ...$exception->failures);
                } else {
                    $failures[] = $exception;
                }
            }
        }

        if ($failures !== []) {
            /** @var non-empty-list<Throwable> $failures */
            throw ResetFailedException::fromFailures($failures);
        }
    }
}
