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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\TypoScript\PageTsConfigFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageTsConfigTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'erhaweb/feed-display',
    ];

    /**
     * @throws \JsonException
     */
    #[Test]
    public function pageTsConfigContainsWizardAndPreviewConfigurationForThePlugin(): void
    {
        $pageTsConfig = $this->get(PageTsConfigFactory::class)->create([], new NullSite())->getPageTsConfigArray();

        self::assertSame(
            'feed-display',
            $pageTsConfig['mod.']['wizards.']['newContentElement.']['wizardItems.']['plugins.']['elements.']['feeddisplay_pi1.']['iconIdentifier']
        );
        self::assertSame(
            'feeddisplay_pi1',
            $pageTsConfig['mod.']['wizards.']['newContentElement.']['wizardItems.']['plugins.']['elements.']['feeddisplay_pi1.']['tt_content_defValues.']['CType']
        );
        self::assertSame(
            'EXT:feed_display/Resources/Private/Templates/Backend/Preview.html',
            $pageTsConfig['mod.']['web_layout.']['tt_content.']['preview.']['feeddisplay_pi1']
        );
    }
}
