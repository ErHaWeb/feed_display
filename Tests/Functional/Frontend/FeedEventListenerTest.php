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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;

final class FeedEventListenerTest extends AbstractFeedFrontendTestCase
{
    protected array $testExtensionsToLoad = [
        'erhaweb/feed-display',
        __DIR__ . '/../Fixtures/Extensions/feed_display_test_listener',
    ];

    #[Test]
    #[IgnoreDeprecations]
    public function eventListenerCanModifyAndSuppressItems(): void
    {
        $feedUrl = $this->writeFeedFixture('Event feed', ['First item', 'Second item']);
        $this->initializeFrontendRootPage($feedUrl);

        $body = $this->requestPage();

        self::assertStringContainsString('First item (modified)', $body);
        self::assertStringNotContainsString('Second item', $body);
    }
}
