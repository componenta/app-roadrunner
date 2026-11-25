<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner\Worker;

/** @internal Shared runtime selection for the HTTP adapter and compatibility factory. */
final class WorkerMode
{
    private function __construct() {}

    public static function isHttp(): bool
    {
        $mode = $_SERVER['RR_MODE'] ?? $_ENV['RR_MODE'] ?? getenv('RR_MODE');

        return is_string($mode) && strtolower(trim($mode)) === 'http';
    }
}
