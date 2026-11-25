#!/usr/bin/env php
<?php

declare(strict_types=1);

use Componenta\App\Scope;
use Componenta\Stdlib\PathResolver;

use function Componenta\App\run;

$root = dirname(__DIR__);

// Guard the Goridge STDOUT transport during Componenta config/container
// bootstrap, before Worker::create() installs RoadRunner's own side-effect
// interceptor. The guard is intentionally non-removable. Direct writes to
// the STDOUT resource are still forbidden.
ob_start(static function (string $chunk, int $_phase): string {
    if ($chunk !== '') {
        file_put_contents('php://stderr', $chunk);
    }

    return '';
}, 1, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
    if ((error_reporting() & $errno) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $errno, $file, $line);
});

try {
    require $root . '/vendor/autoload.php';
    run(Scope::HTTP, new PathResolver($root));
} catch (Throwable $exception) {
    error_log((string) $exception);
    exit(1);
}
