<?php

declare(strict_types=1);

namespace Componenta\App\RoadRunner;

final class ConfigKey
{
    public const string ROOT = 'roadrunner';
    public const string HTTP_CHUNK_SIZE = self::ROOT . '.http.chunk_size';
    public const string WORKER_INTERCEPT_SIDE_EFFECTS = self::ROOT . '.worker.intercept_side_effects';
    public const string RESETTERS = self::ROOT . '.resetters';

    private function __construct() {}
}
