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

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

(static function () {
    $pluginSignature = ExtensionUtility::registerPlugin(
        'FeedDisplay',
        'Pi1',
        'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_title',
        'feed-display',
        'plugins',
        'LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_plus_wiz_description',
    );

    $GLOBALS['TCA']['tt_content']['types']['list']['subtypes_excludelist'][$pluginSignature] = 'recursive,select_key,pages';
    $GLOBALS['TCA']['tt_content']['types']['list']['subtypes_addlist'][$pluginSignature] = 'pi_flexform';

    ExtensionManagementUtility::addPiFlexFormValue(
        $pluginSignature,
        'FILE:EXT:feed_display/Configuration/FlexForms/FeedDisplay.xml'
    );
})();
