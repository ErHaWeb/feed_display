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

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || die();

(static function (): void {
    $pluginSignature = ExtensionUtility::registerPlugin(
        'FeedDisplay',
        'Pi1',
        'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_title',
        'feed-display',
        'plugins',
        'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_plus_wiz_description',
    );

    ExtensionManagementUtility::addToAllTCAtypes(
        'tt_content',
        '--div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:plugin, pi_flexform',
        $pluginSignature,
        'after:subheader',
    );

    /*
     * TYPO3 v13/v14 compatibility:
     *
     * TYPO3 v13 does not support the FlexForm as the 7th registerPlugin()
     * argument. TYPO3 v14 deprecates addPiFlexFormValue().
     *
     * Keep this version split as long as TYPO3 v13 is supported.
     *
     * When TYPO3 v13 support is dropped, replace the registerPlugin() call above with:
     *
     * $pluginSignature = ExtensionUtility::registerPlugin(
     *     'FeedDisplay',
     *     'Pi1',
     *     'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_title',
     *     'feed-display',
     *     'plugins',
     *     'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_plus_wiz_description',
     *     'FILE:EXT:feed_display/Configuration/FlexForms/FeedDisplay.xml',
     * );
     *
     * Then remove the manual FlexForm registration below.
     */
    if ((new Typo3Version())->getMajorVersion() >= 14) {
        $GLOBALS['TCA']['tt_content']['types'][$pluginSignature]['columnsOverrides']['pi_flexform']['config']['ds'] = 'FILE:EXT:feed_display/Configuration/FlexForms/FeedDisplay.xml';

        return;
    }

    // TYPO3 v13 fallback. Indirect call avoids v14 deprecation reports in static analysis.
    $addPiFlexFormValue = \Closure::fromCallable([ExtensionManagementUtility::class, 'addPiFlexFormValue']);
    $addPiFlexFormValue('*', 'FILE:EXT:feed_display/Configuration/FlexForms/FeedDisplay.xml', $pluginSignature);
})();
