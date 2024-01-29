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

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

(static function () {
    /**
     * Adding the default Page TSconfig
     */
    $versionInformation = GeneralUtility::makeInstance(Typo3Version::class);
    if ($versionInformation->getMajorVersion() < 12) {
        ExtensionManagementUtility::addPageTSConfig(trim(
            '
                @import "EXT:feed_display/Configuration/page.tsconfig"
            '
        ));
    }

    /**
     * Add plugin configuration
     */
    ExtensionUtility::configurePlugin(
        'FeedDisplay',
        'Pi1',
        [
            ErHaWeb\FeedDisplay\Controller\FeedController::class => 'display',
        ],
        []
    );

    /**
     * Cache Registration
     */
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['feeddisplay'] ??= [];

    /*
     * Load SimplePie library from phar file if not in composer mode
     */
    if (!class_exists(\SimplePie\SimplePie::class) && !Environment::isComposerMode()) {
        require_once 'phar://' . ExtensionManagementUtility::extPath('feed_display') . 'Libraries/simplepie-simplepie.phar/vendor/autoload.php';
    }
})();