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

namespace ErHaWeb\FeedDisplay\Upgrades;

// TYPO3 v13/v14 compatibility bridge:
// The AbstractListTypeToCTypeUpdate class was moved from EXT:install to EXT:core.
// When dropping TYPO3 v13 support and targeting TYPO3 v14/v15 only,
// extend TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate directly.
if (class_exists(\TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate::class)) {
    abstract class AbstractFeedDisplayListTypeToCTypeUpdate extends \TYPO3\CMS\Core\Upgrades\AbstractListTypeToCTypeUpdate {}
} else {
    abstract class AbstractFeedDisplayListTypeToCTypeUpdate extends \TYPO3\CMS\Install\Updates\AbstractListTypeToCTypeUpdate {}
}
