<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Reset;

interface RequestResetterInterface
{
    public function reset(): void;
}
