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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

final class LiveFeedSmokeTest extends AbstractFeedFrontendTestCase
{
    private const TYPO3_NEWS_RSS_URL = 'https://news.typo3.com/rss';

    #[Test]
    #[Group('live-feed')]
    public function liveTypo3NewsFeedRendersWithoutExtensionErrorState(): void
    {
        $this->initializeFrontendRootPage(self::TYPO3_NEWS_RSS_URL, [
            'cacheDuration' => 0,
            'maxFeedCount' => 1,
            'getFields.feed' => 'title,subscribe_url',
            'getFields.items' => 'title,link',
        ]);

        $body = $this->requestPage();

        self::assertGreaterThan(0, substr_count($body, 'class="item-title"'));
        self::assertStringContainsString('news.typo3.com', $body);
        self::assertStringNotContainsString('Sorry, the feed could not be fetched.', $body);
        self::assertStringNotContainsString('Sorry, no items could be fetched.', $body);
    }
}
