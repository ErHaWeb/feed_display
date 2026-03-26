<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    $config->addNamedFilter(NamedFilter::fromString('typo3/cms-backend'));
    $config->addNamedFilter(NamedFilter::fromString('typo3/cms-frontend'));
    $config->addNamedFilter(NamedFilter::fromString('typo3/cms-fluid'));
    return $config;
};
