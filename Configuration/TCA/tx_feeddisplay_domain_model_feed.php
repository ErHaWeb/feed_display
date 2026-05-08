<?php

/**
 * This file is part of the "feed_display" Extension for TYPO3 CMS.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:tx_feeddisplay_domain_model_feed',
        'label' => 'url',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'hideTable' => true,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:feed_display/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => [
            'showitem' => 'url, --palette--;;visibility',
        ],
    ],
    'palettes' => [
        'visibility' => [
            'showitem' => 'hidden',
        ],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.visible',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true,
                    ],
                ],
            ],
        ],
        'tt_content' => [
            'config' => [
                'type' => 'number',
                'default' => 0,
            ],
        ],
        'url' => [
            'label' => 'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:tx_feeddisplay_domain_model_feed.url',
            'config' => [
                'type' => 'input',
                'required' => true,
                'eval' => 'trim,nospace',
                'placeholder' => 'https://example.org/feed.xml',
            ],
        ],
    ],
];
