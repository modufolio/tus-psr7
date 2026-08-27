<?php

return [
    'includes' => [
        __DIR__.'/vendor/phpstan/phpstan-phpunit/extension.neon',
    ],
    'parameters' => [
        'level' => 5,
        'paths' => [__DIR__.'/src', __DIR__.'/tests'],
        'excludePaths' => [
            'analyseAndScan' => [
                __DIR__.'/vendor/*',
            ],
        ],
        'ignoreErrors' => [
            // Redundant PHPUnit type assertions (assertIsInt on a value
            // PHPStan already knows is an int). Test-readability noise that
            // documents the contract under test, never a bug.
            [
                'identifier' => 'method.alreadyNarrowedType',
                'path' => __DIR__.'/tests/*',
                'reportUnmatched' => false,
            ],
        ],
    ],
];
