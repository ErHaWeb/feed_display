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

use ErHaWeb\FeedDisplay\Service\FeedFetcher;
use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Service\FetchResult;
use ErHaWeb\FeedDisplay\Service\RefreshResult;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedRefresherTest extends UnitTestCase
{
    private const URL = 'https://example.com/feed.xml';
    private const NOW = 10000;

    private FeedFetcher&MockObject $fetcher;
    private FeedStorage&MockObject $storage;
    private LockingStrategyInterface&MockObject $locker;
    private CacheManager&MockObject $cacheManager;
    private FeedRefresher $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetcher = $this->createMock(FeedFetcher::class);
        $this->storage = $this->createMock(FeedStorage::class);
        $this->locker = $this->createMock(LockingStrategyInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($this->locker);
        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
        $this->cacheManager = $this->createMock(CacheManager::class);
        $this->subject = new FeedRefresher($this->fetcher, $this->storage, $lockFactory, $context, $this->cacheManager);
    }

    #[Test]
    public function changedResponseIsStoredAndReportedAsUpdated(): void
    {
        $this->fetcher->method('fetch')->with(self::URL)->willReturn(FetchResult::success('<rss/>'));
        $this->storage->expects(self::once())->method('storeSuccess')->with(self::URL, '<rss/>', self::NOW)->willReturn(true);
        $this->cacheManager->expects(self::once())->method('flushCachesInGroupByTag')
            ->with('pages', FeedStorage::getPageCacheTag(self::URL));
        $this->locker->expects(self::once())->method('release');

        self::assertSame(RefreshResult::Updated, $this->subject->refresh(self::URL));
    }

    #[Test]
    public function identicalResponseIsReportedAsUnchanged(): void
    {
        $this->fetcher->method('fetch')->willReturn(FetchResult::success('<rss/>'));
        $this->storage->method('storeSuccess')->willReturn(false);
        $this->cacheManager->expects(self::never())->method('flushCachesInGroupByTag');

        self::assertSame(RefreshResult::Unchanged, $this->subject->refresh(self::URL));
    }

    #[Test]
    public function failedFetchOnlyRecordsTheError(): void
    {
        $this->fetcher->method('fetch')->willReturn(FetchResult::failure('Connection refused'));
        $this->storage->expects(self::never())->method('storeSuccess');
        $this->storage->expects(self::once())->method('storeFailure')->with(self::URL, 'Connection refused', self::NOW);
        $this->cacheManager->expects(self::never())->method('flushCachesInGroupByTag');
        $this->locker->expects(self::once())->method('release');

        self::assertSame(RefreshResult::Failed, $this->subject->refresh(self::URL));
    }

    #[Test]
    public function concurrentFetchIsNotAwaitedByDefault(): void
    {
        $this->locker->expects(self::once())->method('acquire')
            ->with(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)
            ->willThrowException(new LockAcquireWouldBlockException('busy', 1));
        $this->fetcher->expects(self::never())->method('fetch');
        $this->locker->expects(self::never())->method('release');

        self::assertSame(RefreshResult::Locked, $this->subject->refresh(self::URL));
    }

    #[Test]
    public function concurrentFetchIsAwaitedOnRequest(): void
    {
        $this->locker->expects(self::once())->method('acquire')
            ->with(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE)
            ->willReturn(true);
        $this->fetcher->method('fetch')->willReturn(FetchResult::success('<rss/>'));
        $this->storage->method('storeSuccess')->willReturn(true);

        self::assertSame(RefreshResult::Updated, $this->subject->refresh(self::URL, true));
    }
}
