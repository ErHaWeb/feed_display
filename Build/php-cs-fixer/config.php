<?php

declare(strict_types=1);

use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use TYPO3\CodingStandards\CsFixerConfig;

$config = CsFixerConfig::create();
$config->setParallelConfig(ParallelConfigFactory::detect());
$config
    ->setCacheFile(__DIR__ . '/../../.Build/php-cs-fixer/.php-cs-fixer.cache')
    ->getFinder()
    ->in(__DIR__ . '/../../Classes')
    ->in(__DIR__ . '/../../Configuration')
    ->in(__DIR__ . '/../../Tests')
    ->exclude('Fixtures');

return $config;
