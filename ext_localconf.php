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

use ErHaWeb\FeedDisplay\Bootstrap\SimplePieBootstrap;
use ErHaWeb\FeedDisplay\Controller\FeedController;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || die();

(static function () {
    // Add plugin configuration.
    ExtensionUtility::configurePlugin(
        'FeedDisplay',
        'Pi1',
        [
            FeedController::class => 'display',
        ]
    );

    // Register extension cache.
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['feeddisplay'] ??= [];

    (new SimplePieBootstrap(ExtensionManagementUtility::extPath('feed_display')))->ensureLibraryIsLoaded();
})();
