<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\ValueObject\PhpVersion;
use Ssch\TYPO3Rector\CodeQuality\General\ExtEmConfRector;
use Ssch\TYPO3Rector\CodeQuality\General\GeneralUtilityMakeInstanceToConstructorPropertyRector;
use Ssch\TYPO3Rector\CodeQuality\General\InjectMethodToConstructorInjectionRector;
use Ssch\TYPO3Rector\Configuration\Typo3Option;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../Build',
        __DIR__ . '/../../Classes',
        __DIR__ . '/../../Tests',
        __DIR__ . '/../../ext_emconf.php',
        __DIR__ . '/../../ext_localconf.php',
    ])
    ->withPhpSets(php81: true)
    ->withPhpVersion(PhpVersion::PHP_81)
    ->withSets([
        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,
        Typo3LevelSetList::UP_TO_TYPO3_12,
    ])
    ->withPHPStanConfigs([Typo3Option::PHPSTAN_FOR_RECTOR_PATH])
    ->withSkip([
        NullToStrictStringFuncCallArgRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        GeneralUtilityMakeInstanceToConstructorPropertyRector::class,
        InjectMethodToConstructorInjectionRector::class,
    ])
    ->withImportNames(true, true, false, true)
    ->withConfiguredRule(ExtEmConfRector::class, [
        ExtEmConfRector::PHP_VERSION_CONSTRAINT => '8.1.0-8.3.99',
        ExtEmConfRector::TYPO3_VERSION_CONSTRAINT => '12.4.0-13.4.99',
        ExtEmConfRector::ADDITIONAL_VALUES_TO_BE_REMOVED => [],
    ])
;
