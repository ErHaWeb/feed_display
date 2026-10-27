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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Storage;

use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FeedStorageTest extends FunctionalTestCase
{
    private const URL = 'https://example.com/feed.xml';

    protected array $testExtensionsToLoad = [
        'erhaweb/feed-display',
    ];

    private FeedStorage $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(FeedStorage::class);
    }

    #[Test]
    public function findReturnsNullForUnknownUrl(): void
    {
        self::assertNull($this->subject->find(self::URL));
    }

    #[Test]
    public function storeSuccessReportsWhetherTheResponseChanged(): void
    {
        self::assertTrue($this->subject->storeSuccess(self::URL, '<rss>1</rss>', 100));
        self::assertFalse($this->subject->storeSuccess(self::URL, '<rss>1</rss>', 200));
        self::assertTrue($this->subject->storeSuccess(self::URL, '<rss>2</rss>', 300));

        $storedFeed = $this->subject->find(self::URL);
        self::assertNotNull($storedFeed);
        self::assertSame(self::URL, $storedFeed->url);
        self::assertSame('<rss>2</rss>', $storedFeed->rawBody);
        self::assertSame(hash('sha256', '<rss>2</rss>'), $storedFeed->bodyHash);
        self::assertSame(300, $storedFeed->fetchedAt);
    }

    #[Test]
    public function storeFailureKeepsTheLastSuccessfulResponse(): void
    {
        $this->subject->storeSuccess(self::URL, '<rss>1</rss>', 100);
        $this->subject->storeFailure(self::URL, 'Connection refused', 200);

        $storedFeed = $this->subject->find(self::URL);
        self::assertNotNull($storedFeed);
        self::assertSame('<rss>1</rss>', $storedFeed->rawBody);
        self::assertSame(100, $storedFeed->fetchedAt);
        self::assertSame(200, $storedFeed->lastAttemptAt);
        self::assertSame('Connection refused', $storedFeed->lastError);

        $this->subject->storeSuccess(self::URL, '<rss>1</rss>', 300);
        self::assertSame('', $this->subject->find(self::URL)?->lastError);
    }

    #[Test]
    public function storeFailureCreatesARowWithoutContentForUnknownUrl(): void
    {
        $this->subject->storeFailure(self::URL, 'Not found', 100);

        $storedFeed = $this->subject->find(self::URL);
        self::assertNotNull($storedFeed);
        self::assertFalse($storedFeed->hasContent());
    }

    #[Test]
    public function usedUrlsCanBeListedAndPruned(): void
    {
        $this->subject->markUsed('https://example.com/old.xml', 100);
        $this->subject->markUsed('https://example.com/recent.xml', 500);
        $this->subject->markUsed('https://example.com/recent.xml', 500);

        self::assertSame(['https://example.com/recent.xml'], $this->subject->findUrlsUsedSince(200));
        self::assertSame(1, $this->subject->deleteUnusedSince(200));
        self::assertNull($this->subject->find('https://example.com/old.xml'));
        self::assertNotNull($this->subject->find('https://example.com/recent.xml'));
    }

    #[Test]
    public function pruningKeepsTheGivenUrls(): void
    {
        $this->subject->markUsed('https://example.com/configured.xml', 100);
        $this->subject->markUsed('https://example.com/old.xml', 100);

        self::assertSame(1, $this->subject->deleteUnusedSince(200, ['https://example.com/configured.xml']));
        self::assertNotNull($this->subject->find('https://example.com/configured.xml'));
    }
}
