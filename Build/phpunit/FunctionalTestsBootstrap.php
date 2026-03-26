<?php

declare(strict_types=1);

$bootstrapCandidates = [
    dirname(__DIR__, 2) . '/.Build/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php',
    dirname(__DIR__, 2) . '/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php',
];

foreach ($bootstrapCandidates as $bootstrap) {
    if (file_exists($bootstrap)) {
        require $bootstrap;
        return;
    }
}

throw new RuntimeException('No TYPO3 functional testing bootstrap found.');
