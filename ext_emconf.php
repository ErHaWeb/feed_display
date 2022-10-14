<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Feed Display',
    'description' => 'Fetches and parses RSS and Atom web feeds with the SimplePie library and prepares them for frontend display',
    'category' => 'plugin',
    'author' => 'Eric Bode',
    'author_email' => 'eric.bode@gmx.de',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '0.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-12.99.99',
        ],
    ],
];