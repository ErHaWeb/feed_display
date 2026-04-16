<?php

declare(strict_types=1);

return [
    'directories' => [
        '\.build(?:\/|$)',
        '\.cache(?:\/|$)',
        '\.composer(?:\/|$)',
        '\.ddev(?:\/|$)',
        '\.git(?:\/|$)',
        '\.github(?:\/|$)',
        'build(?:\/|$)',
        'config(?:\/|$)',
        'Documentation-GENERATED-temp(?:\/|$)',
        'Tests(?:\/|$)',
        'node_modules(?:\/|$)',
        'playwright\/\.auth(?:\/|$)',
        'playwright-report(?:\/|$)',
        'tailor-version-artefact(?:\/|$)',
        'test-results(?:\/|$)',
        'var(?:\/|$)',
    ],
    'files' => [
        '^\.DS_Store',
        '^\.editorconfig',
        '^\.gitattributes',
        '^\.gitignore',
        '^\.php-cs-fixer\.cache',
        '^\.phpunit\.cache',
        '^composer\.json\.orig',
        '^composer\.json\.testing',
        '^composer\.lock',
        '^crowdin\.yml',
    ],
];
