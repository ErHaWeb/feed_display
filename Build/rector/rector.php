<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\Config\RectorConfig;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../Classes',
        __DIR__ . '/../../Configuration',
        __DIR__ . '/../../Tests',
        __DIR__ . '/../../ext_localconf.php',
    ])
    ->withSkip([
        CompleteDynamicPropertiesRector::class => [
            __DIR__ . '/../../Tests/Unit/Controller/FeedControllerTest.php',
            __DIR__ . '/../../Tests/Functional/Frontend/FeedFrontendTest.php',
        ],
    ])
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: false)
    ->withSets([
        Typo3LevelSetList::UP_TO_TYPO3_12,
    ]);
