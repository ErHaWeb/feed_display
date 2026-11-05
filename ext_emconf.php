<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Feed Display',
    'description' => 'Fetches and parses RSS and Atom web feeds with the SimplePie library and prepares them for frontend display',
    'category' => 'plugin',
    'author' => 'Eric Harrer',
    'author_email' => 'info@eric-harrer.de',
    'author_company' => 'eric-harrer.de',
    'state' => 'stable',
    'version' => '3.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
            'php' => '8.2.0-8.5.99',
        ],
    ],
    // TYPO3 13 Classic registers the bundled classes here; TYPO3 14 uses providesPackages.
    'autoload' => [
        'psr-4' => [
            'ErHaWeb\\FeedDisplay\\' => 'Classes',
            'SimplePie\\' => 'Resources/Private/Php/ComposerVendor/simplepie/simplepie/src/',
        ],
        'classmap' => [
            'Resources/Private/Php/ComposerVendor/simplepie/simplepie/library/',
        ],
    ],
];
