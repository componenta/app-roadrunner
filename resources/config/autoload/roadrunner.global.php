<?php

declare(strict_types=1);

return [
    'roadrunner' => [
        'http' => [
            // Keep buffering enabled by default. RoadRunner's chunked PSR-7
            // transport requires seekable response streams.
            'chunk_size' => 0,
        ],
        'worker' => [
            'intercept_side_effects' => true,
        ],
        // Service ids implementing RequestResetterInterface, in execution order.
        'resetters' => [],
    ],
];
