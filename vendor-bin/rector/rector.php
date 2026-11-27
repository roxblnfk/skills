<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DowngradePhp82\Rector\FunctionLike\DowngradeStandaloneNullTrueFalseReturnTypeRector;

// Lives next to the isolated Rector install in vendor-bin/rector/; source paths reach the project root via ../../.
// The plugin supports PHP 8.1: the downgrade sets keep the plugin code and the bundled skill scripts free of newer syntax.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../src',
        __DIR__ . '/../../bin/skills',
        __DIR__ . '/../../resources/skills/llm-skills/scripts',
    ])
    ->withCache(__DIR__ . '/../../runtime/rector')
    ->withDowngradeSets(php81: true)
    ->withSkip([
        // Widens `T|false|null` to `T|bool|null`, though a union with `false` is valid since PHP 8.0.
        DowngradeStandaloneNullTrueFalseReturnTypeRector::class,
    ]);
