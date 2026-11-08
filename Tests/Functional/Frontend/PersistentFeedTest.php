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

use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;

final class PersistentFeedTest extends AbstractFeedFrontendTestCase
{
    #[Test]
    public function cachedFeedSurvivesAFullFlushAndAnUnavailableSource(): void
    {
        $url = $this->writeFeedFixture('Persisted title', ['Original item']);
        $this->initializeFrontendRootPage($url, ['cacheDuration' => 600]);
        self::assertStringContainsString('Persisted title', $this->requestPage());
        $storage = $this->get(FeedStorage::class);
        $stored = $storage->find($url);
        self::assertNotNull($stored);
        self::assertNotNull($stored->document);
        unlink($url);
        $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->update(FeedStorage::TABLE, ['last_attempt_at' => 0], ['url_hash' => FeedStorage::hashUrl($url)]);
        $this->get(CacheManager::class)->flushCaches();
        self::assertStringContainsString('Original item', $this->requestPage());
        self::assertSame($stored->revision, $storage->find($url)?->revision);
        self::assertSame($stored->fetchedAt, $storage->find($url)->fetchedAt);
        self::assertNotSame('', $storage->find($url)->lastError);
        $this->get(CacheManager::class)->flushCaches();
        self::assertStringContainsString('Original item', $this->requestPage());
    }

    #[Test]
    #[DataProvider('uncachedDurations')]
    public function uncachedLiveFetchesStillPersistAndFallBack(int $duration): void
    {
        $url = $this->writeFeedFixture('Initial', ['Original item']);
        $this->initializeFrontendRootPage($url, ['cacheDuration' => $duration]);
        self::assertStringContainsString('Initial', $this->requestPage());
        self::assertNotNull($this->get(FeedStorage::class)->find($url)?->document);
        $this->writeFeedFixture('Updated', []);
        self::assertStringContainsString('Updated', $this->requestPage());
        unlink($url);
        $body = $this->requestPage();
        self::assertStringContainsString('Updated', $body);
        self::assertStringNotContainsString('Original item', $body);
    }

    /** @return iterable<string, array{int}> */
    public static function uncachedDurations(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    public function legacyDisplayCacheRemainsUsableBeforeTheFirstNewFetch(): void
    {
        $url = $this->writeFeedFixture('Old cache', ['Item']);
        $this->initializeFrontendRootPage($url, ['cacheDuration' => 600]);
        self::assertStringContainsString('Old cache', $this->requestPage());
        $cached = $this->getCachedFeedData();
        self::assertIsArray($cached);
        unset($cached['_feeddisplay_revision']);
        $this->setCachedFeedData($cached);
        $this->get(FeedStorage::class)->remove($url);
        $this->writeFeedFixture('New source', ['Item']);
        self::assertStringContainsString('Old cache', $this->requestPage());
        self::assertNull($this->get(FeedStorage::class)->find($url));
        $this->get(CacheManager::class)->getCache('feeddisplay')->flush();
        self::assertStringContainsString('New source', $this->requestPage());
        self::assertNotNull($this->get(FeedStorage::class)->find($url)?->document);
    }

    #[Test]
    public function liveRenderingKeepsWorkingWhenTheSchemaUpdateWasMissed(): void
    {
        $url = $this->writeFeedFixture('Without schema', ['Item']);
        $this->initializeFrontendRootPage($url, ['cacheDuration' => 600]);
        $manager = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->createSchemaManager();
        $manager->renameTable(FeedStorage::TABLE, FeedStorage::TABLE . '_unavailable');
        try {
            self::assertStringContainsString('Without schema', $this->requestPage());
            unlink($url);
            self::assertStringContainsString('Without schema', $this->requestPage());
        } finally {
            $manager->renameTable(FeedStorage::TABLE . '_unavailable', FeedStorage::TABLE);
        }
    }

    #[Test]
    public function liveFirstRequestCanReadWithoutWritingWhileAnotherWorkerHoldsTheLock(): void
    {
        $url = $this->writeFeedFixture('Parallel live', ['Item']);
        $this->initializeFrontendRootPage($url, ['cacheDuration' => 600]);
        $mode = \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $lock = $this->get(\TYPO3\CMS\Core\Locking\LockFactory::class)->createLocker('feed_display_' . FeedStorage::hashUrl($url), $mode);
        self::assertTrue($lock->acquire($mode));
        try {
            self::assertStringContainsString('Parallel live', $this->requestPage());
            self::assertNull($this->get(FeedStorage::class)->find($url));
        } finally {
            $lock->release();
        }
        self::assertStringContainsString('Parallel live', $this->requestPage());
        self::assertNotNull($this->get(FeedStorage::class)->find($url)?->document);
    }
}
