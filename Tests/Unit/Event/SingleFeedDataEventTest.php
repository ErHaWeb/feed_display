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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Event;

use ErHaWeb\FeedDisplay\Event\SingleFeedDataEvent;
use PHPUnit\Framework\Attributes\Test;
use SimplePie\Item;
use SimplePie\SimplePie;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SingleFeedDataEventTest extends UnitTestCase
{
    #[Test]
    public function gettersReturnInitializedObjectsAndSettersOverrideValues(): void
    {
        $itemProperties = [
            'title' => 'Initial title',
        ];
        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
        ];
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->getMock();
        $feed = $this->getMockBuilder(SimplePie::class)->disableOriginalConstructor()->getMock();

        $event = new SingleFeedDataEvent($itemProperties, $item, $settings, $feed);

        self::assertSame($itemProperties, $event->getItemProperties());
        self::assertSame($item, $event->getItem());
        self::assertSame($settings, $event->getSettings());
        self::assertSame($feed, $event->getFeed());

        $changedItemProperties = [
            'title' => 'Changed title',
            'custom' => 'value',
        ];
        $changedSettings = [
            'feedUrl' => 'https://example.com/other.xml',
        ];
        $changedItem = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->getMock();
        $changedFeed = $this->getMockBuilder(SimplePie::class)->disableOriginalConstructor()->getMock();

        $event->setItemProperties($changedItemProperties);
        $event->setSettings($changedSettings);
        $event->setItem($changedItem);
        $event->setFeed($changedFeed);

        self::assertSame($changedItemProperties, $event->getItemProperties());
        self::assertSame($changedSettings, $event->getSettings());
        self::assertSame($changedItem, $event->getItem());
        self::assertSame($changedFeed, $event->getFeed());
    }
}
