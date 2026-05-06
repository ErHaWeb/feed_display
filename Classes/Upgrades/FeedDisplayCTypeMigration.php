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

// TYPO3 v13/v14 compatibility: Use the EXT:install attribute.
// For TYPO3 v14/v15-only compatibility, switch to:
// use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;

#[UpgradeWizard('feedDisplayCTypeMigration')]
final class FeedDisplayCTypeMigration extends AbstractFeedDisplayListTypeToCTypeUpdate
{
    public function getTitle(): string
    {
        return 'Migrate Feed Display content elements from list_type to CType';
    }

    public function getDescription(): string
    {
        return 'Migrates Feed Display plugin records from CType=list and list_type=feeddisplay_pi1 '
            . 'to the dedicated CType feeddisplay_pi1 and updates backend group permissions.';
    }

    /**
     * @return array<string, string>
     */
    protected function getListTypeToCTypeMapping(): array
    {
        return [
            'feeddisplay_pi1' => 'feeddisplay_pi1',
        ];
    }
}
