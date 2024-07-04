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

defined('TYPO3') || die();

(static function () {
    /**
     * Extension key
     */
    $extKey = 'feed_display';

    /**
     * TypoScript path
     */
    $path = 'Configuration/TypoScript';

    /**
     * Locallang file path
     */
    $locallangFilePath = 'LLL:EXT:' . $extKey . '/Resources/Private/Language/locallang_db.xlf';

    /**
     * Static TypoScript include title
     */
    $title = $locallangFilePath . ':sys_template.TypoScript.' . $extKey . '_title';

    /**
     * Add static TypoScript (constants and setup) directly through TCA instead of the API function to be able to translate the title
     */
    if (is_array($GLOBALS['TCA']['sys_template']['columns'])) {
        $value = str_replace(',', '', 'EXT:' . $extKey . '/' . $path);
        $GLOBALS['TCA']['sys_template']['columns']['include_static_file']['config']['items'][] = ['label' => $title, 'value' => $value];
    }
})();
