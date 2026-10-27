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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Service;

use ErHaWeb\FeedDisplay\Service\FeedProvider;
use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Service\FetchMode;
use ErHaWeb\FeedDisplay\Service\RefreshResult;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Storage\StoredFeed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedProviderTest extends UnitTestCase
{
    private const URL = 'https://example.com/feed.xml';
    private const NOW = 10000;

    private FeedStorage&MockObject $storage;
    private FeedRefresher&MockObject $refresher;
    private FeedProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = $this->createMock(FeedStorage::class);
        $this->refresher = $this->createMock(FeedRefresher::class);
        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
        $this->subject = new FeedProvider($this->storage, $this->refresher, $context);
    }

    #[Test]
    public function freshResponseIsReturnedWithoutFetching(): void
    {
        $storedFeed = $this->createStoredFeed(fetchedAt: self::NOW - 100, lastUsedAt: self::NOW);
        $this->storage->method('find')->willReturn($storedFeed);
        $this->storage->expects(self::never())->method('markUsed');
        $this->refresher->expects(self::never())->method('refresh');

        self::assertSame($storedFeed, $this->subject->provide(self::URL, 600));
    }

    #[Test]
    public function expiredResponseIsRefreshedWithoutWaitingForConcurrentFetches(): void
    {
        $expiredFeed = $this->createStoredFeed(fetchedAt: self::NOW - 1000, lastUsedAt: self::NOW);
        $refreshedFeed = $this->createStoredFeed(fetchedAt: self::NOW, lastUsedAt: self::NOW);
        $this->storage->method('find')->willReturnOnConsecutiveCalls($expiredFeed, $refreshedFeed);
        $this->refresher->expects(self::once())->method('refresh')->with(self::URL, false)->willReturn(RefreshResult::Updated);

        self::assertSame($refreshedFeed, $this->subject->provide(self::URL, 600));
    }

    #[Test]
    public function zeroLifetimeRefreshesOnEveryCall(): void
    {
        $storedFeed = $this->createStoredFeed(fetchedAt: self::NOW, lastUsedAt: self::NOW);
        $this->storage->method('find')->willReturn($storedFeed);
        $this->refresher->expects(self::once())->method('refresh')->willReturn(RefreshResult::Unchanged);

        $this->subject->provide(self::URL, 0);
    }

    #[Test]
    public function storedResponseIsReturnedIfAnotherProcessIsFetching(): void
    {
        $expiredFeed = $this->createStoredFeed(fetchedAt: self::NOW - 1000, lastUsedAt: self::NOW);
        $this->storage->expects(self::once())->method('find')->willReturn($expiredFeed);
        $this->refresher->method('refresh')->willReturn(RefreshResult::Locked);

        self::assertSame($expiredFeed, $this->subject->provide(self::URL, 600));
    }

    #[Test]
    public function unknownUrlIsRecordedAndFetchedWaitingForConcurrentFetches(): void
    {
        $fetchedFeed = $this->createStoredFeed(fetchedAt: self::NOW, lastUsedAt: self::NOW);
        $this->storage->method('find')->willReturnOnConsecutiveCalls(null, $fetchedFeed);
        $this->storage->expects(self::once())->method('markUsed')->with(self::URL, self::NOW);
        $this->refresher->expects(self::once())->method('refresh')->with(self::URL, true)->willReturn(RefreshResult::Updated);

        self::assertSame($fetchedFeed, $this->subject->provide(self::URL, 600));
    }

    #[Test]
    public function usageIsRecordedOncePerInterval(): void
    {
        $storedFeed = $this->createStoredFeed(fetchedAt: self::NOW, lastUsedAt: self::NOW - 7200);
        $this->storage->method('find')->willReturn($storedFeed);
        $this->storage->expects(self::once())->method('markUsed')->with(self::URL, self::NOW);

        $this->subject->provide(self::URL, 600);
    }

    #[Test]
    public function schedulerModeRendersAnExpiredResponseWithoutFetching(): void
    {
        $expiredFeed = $this->createStoredFeed(fetchedAt: self::NOW - 100000, lastUsedAt: self::NOW);
        $this->storage->method('find')->willReturn($expiredFeed);
        $this->refresher->expects(self::never())->method('refresh');

        self::assertSame($expiredFeed, $this->subject->provide(self::URL, 600, FetchMode::Scheduler));
    }

    #[Test]
    public function schedulerModeFetchesAFeedThatWasNeverFetchedSuccessfully(): void
    {
        $failedFeed = new StoredFeed(self::URL, lastAttemptAt: self::NOW - 10, lastError: 'Not found', lastUsedAt: self::NOW);
        $fetchedFeed = $this->createStoredFeed(fetchedAt: self::NOW, lastUsedAt: self::NOW);
        $this->storage->method('find')->willReturnOnConsecutiveCalls($failedFeed, $fetchedFeed);
        $this->refresher->expects(self::once())->method('refresh')->with(self::URL, true)->willReturn(RefreshResult::Updated);

        self::assertSame($fetchedFeed, $this->subject->provide(self::URL, 600, FetchMode::Scheduler));
    }

    private function createStoredFeed(int $fetchedAt, int $lastUsedAt): StoredFeed
    {
        return new StoredFeed(self::URL, '<rss/>', hash('sha256', '<rss/>'), $fetchedAt, $fetchedAt, '', $lastUsedAt);
    }
}
