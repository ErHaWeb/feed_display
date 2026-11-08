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

use ErHaWeb\FeedDisplay\Service\FeedDataService;
use ErHaWeb\FeedDisplay\Service\FeedFetcher;
use ErHaWeb\FeedDisplay\Service\FeedProvider;
use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Tests\Fixtures\StoredDocument;
use ErHaWeb\FeedDisplay\Tests\Functional\Fixtures\FeedHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;

final class PersistentHttpFeedTest extends AbstractHttpFeedTestCase
{
    #[Test]
    public function discoveredFeedSurvivesOutageAndFullCacheFlush(): void
    {
        $feedUrl = 'https://feed.example.test/discovered.xml';
        FeedHttp::$responses[self::URL] = new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], '<html><head><link rel="alternate" type="application/rss+xml" href="' . $feedUrl . '"></head></html>');
        FeedHttp::$responses[$feedUrl] = FeedHttp::rss('DISCOVERED_ORIGINAL');
        $this->initializeFrontendRootPage(self::URL, ['cacheDuration' => 3600]);
        self::assertStringContainsString('DISCOVERED_ORIGINAL', $this->requestPage());
        $before = $this->get(FeedStorage::class)->find(self::URL);
        self::assertNotNull($before?->document);
        FeedHttp::$responses = [];
        $this->get(CacheManager::class)->flushCaches();
        self::assertStringContainsString('DISCOVERED_ORIGINAL', $this->requestPage());
        self::assertSame(1, FeedHttp::countRequests(self::URL));
        self::assertSame(1, FeedHttp::countRequests($feedUrl));
        self::assertSame($before->revision, $this->get(FeedStorage::class)->find(self::URL)?->revision);
    }

    #[Test]
    public function changedLiveSourcesLeaveExistingLivePagesCached(): void
    {
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Original');
        $this->initializeFrontendRootPage(self::URL, ['cacheDuration' => 3600]);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, "config.no_cache = 0\nconfig.cache_period = 7200");
        self::assertStringContainsString('Original', $this->requestPage());
        $pages = $this->getConnectionPool()->getConnectionForTable('cache_pages');
        $identifiers = $pages->select(['identifier', 'expires'], 'cache_pages')->fetchAllAssociative();
        self::assertCount(1, $identifiers);
        self::assertGreaterThan(time() + 3600, (int)$identifiers[0]['expires']);
        foreach ([3600, 0, -1] as $duration) {
            $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->update(FeedStorage::TABLE, ['last_attempt_at' => 1], ['url_hash' => FeedStorage::hashUrl(self::URL)]);
            FeedHttp::$responses[self::URL] = FeedHttp::rss('Changed ' . $duration);
            $data = $this->get(FeedProvider::class)->getData(['feedUrl' => self::URL, 'cacheDuration' => $duration, 'getFields' => ['feed' => 'title']]);
            self::assertSame('Changed ' . $duration, $data['feed']['title']);
            self::assertSame($identifiers, $pages->select(['identifier', 'expires'], 'cache_pages')->fetchAllAssociative());
            self::assertStringContainsString('Original', $this->requestPage());
        }
    }

    #[Test]
    public function validDisplayCacheDoesNotReadOrDecodeACorruptBlob(): void
    {
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Cached success');
        $this->initializeFrontendRootPage(self::URL, ['cacheDuration' => 600]);
        self::assertStringContainsString('Cached success', $this->requestPage());
        $connection = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE);
        $connection->update(FeedStorage::TABLE, ['response_data' => 'corrupt'], ['url_hash' => FeedStorage::hashUrl(self::URL)]);
        self::assertStringContainsString('Cached success', $this->requestPage());
        self::assertStringContainsString('Cached success', $this->requestPage());
        self::assertSame(1, FeedHttp::countRequests(self::URL));
        $this->get(CacheManager::class)->getCache('feeddisplay')->flush();
        // A miss diagnoses and repairs the unusable document despite a positive live TTL.
        $now = (int)$this->get(Context::class)->getPropertyFromAspect('date', 'timestamp');
        $connection->update(FeedStorage::TABLE, ['last_attempt_at' => $now - 61], ['url_hash' => FeedStorage::hashUrl(self::URL)]);
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Repaired');
        self::assertStringContainsString('Repaired', $this->requestPage());
        self::assertSame(2, FeedHttp::countRequests(self::URL));
    }

    #[Test]
    #[DataProvider('writeFailureCases')]
    public function failedWritesKeepTheSuccessfulDisplayResult(int $duration, bool $existing): void
    {
        $real = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE);
        if ($existing) {
            $this->get(FeedStorage::class)->storeSuccess(self::URL, StoredDocument::create('<rss version="2.0"><channel><title>Old</title><description>Test</description></channel></rss>'), 1);
        }
        $before = $this->get(FeedStorage::class)->find(self::URL);
        $connection = $this->createMock(Connection::class);
        $connection->method('select')->willReturnCallback($real->select(...));
        $connection->method('update')->willThrowException(new \RuntimeException('Simulated packet limit'));
        $connection->method('insert')->willThrowException(new \RuntimeException('Simulated packet limit'));
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $storage = new FeedStorage($pool, new NullLogger());
        $fetcher = $this->get(FeedFetcher::class);
        $context = $this->get(Context::class);
        $refresher = new FeedRefresher($storage, $fetcher, $this->get(LockFactory::class), $this->get(CacheManager::class), $context, new NullLogger());
        $provider = new FeedProvider($storage, $refresher, $fetcher, $this->get(FeedDataService::class), $this->get(CacheManager::class)->getCache('feeddisplay'), $context, new NullLogger());
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Fresh but not stored');
        for ($request = 0; $request < 3; ++$request) {
            $data = $provider->getData(['feedUrl' => self::URL, 'cacheDuration' => $duration, 'getFields' => ['feed' => 'title']]);
            self::assertSame('Fresh but not stored', $data['feed']['title']);
            self::assertArrayNotHasKey('_feeddisplay_revision', $data);
        }
        self::assertSame($duration > 0 ? 1 : 3, FeedHttp::countRequests(self::URL));
        $this->get(CacheManager::class)->getCache('feeddisplay')->flush();
        self::assertSame('Fresh but not stored', $provider->getData(['feedUrl' => self::URL, 'cacheDuration' => $duration, 'getFields' => ['feed' => 'title']])['feed']['title']);
        self::assertSame($duration > 0 ? 2 : 4, FeedHttp::countRequests(self::URL));
        self::assertSame($before?->revision, $this->get(FeedStorage::class)->find(self::URL)?->revision);
        self::assertSame($before?->lastAttemptAt, $this->get(FeedStorage::class)->find(self::URL)?->lastAttemptAt);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function writeFailureCases(): iterable
    {
        foreach ([600, 0, -1] as $duration) {
            foreach ([false, true] as $existing) {
                yield $duration . ($existing ? ' old row' : ' no row') => [$duration, $existing];
            }
        }
    }

    #[Test]
    public function missingSchemaStillCachesLiveErrorsForTheConfiguredDuration(): void
    {
        $this->initializeFrontendRootPage(self::URL, ['cacheDuration' => 600]);
        $manager = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->createSchemaManager();
        $manager->renameTable(FeedStorage::TABLE, FeedStorage::TABLE . '_unavailable');
        try {
            self::assertStringContainsString('error-no-feed', $this->requestPage());
            self::assertStringContainsString('error-no-feed', $this->requestPage());
            self::assertSame(1, FeedHttp::countRequests(self::URL));
            $this->get(CacheManager::class)->getCache('feeddisplay')->flush();
            self::assertStringContainsString('error-no-feed', $this->requestPage());
            self::assertSame(2, FeedHttp::countRequests(self::URL));
        } finally {
            $manager->renameTable(FeedStorage::TABLE . '_unavailable', FeedStorage::TABLE);
        }
    }

    #[Test]
    public function liveOutageKeepsTheStoredResponse(): void
    {
        $document = StoredDocument::create((string)FeedHttp::rss('Stored live')->getBody(), self::URL . '/');
        $storage = $this->get(FeedStorage::class);
        $storage->storeSuccess(self::URL, $document, 1);
        $storage->markInvalidated(self::URL, $document->revision());
        $this->initializeFrontendRootPage(self::URL, ['cacheDuration' => 3600]);
        self::assertStringContainsString('Stored live', $this->requestPage());
        self::assertStringContainsString('Stored live', $this->requestPage());
        self::assertSame(1, FeedHttp::countRequests(self::URL));
        $after = $storage->findState(self::URL);
        self::assertNotNull($after);
        self::assertSame($document->revision(), $after->revision);
        self::assertSame(1, $after->fetchedAt);
        self::assertNotSame('', $after->lastError);
    }

    #[Test]
    #[DataProvider('readFailuresAfterPersistence')]
    public function persistedResultsKeepTheirRevisionAndRetryInvalidation(string $failure): void
    {
        $real = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE);
        $reads = 0;
        $connection = self::createStub(Connection::class);
        $connection->method('select')->willReturnCallback(static function (array $columns, string $table, array $where = []) use ($real, &$reads, $failure): \Doctrine\DBAL\Result {
            if (in_array('last_attempt_at', $columns, true) && ++$reads === 3) {
                if ($failure === 'exception') {
                    throw new \RuntimeException('Read failed after successful write');
                }
                $empty = self::createStub(\Doctrine\DBAL\Result::class);
                $empty->method('fetchAssociative')->willReturn(false);
                return $empty;
            }
            return $real->select($columns, $table, $where);
        });
        $connection->method('insert')->willReturnCallback($real->insert(...));
        $connection->method('update')->willReturnCallback($real->update(...));
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $storage = new FeedStorage($pool, new NullLogger());
        $flushes = 0;
        $manager = $this->createMock(CacheManager::class);
        $manager->expects(self::exactly(2))->method('flushCachesInGroupByTag')->willReturnCallback(static function () use (&$flushes): void {
            if (++$flushes === 1) {
                throw new \RuntimeException('Page invalidation temporarily unavailable');
            }
        });
        $context = $this->get(Context::class);
        $fetcher = $this->get(FeedFetcher::class);
        $refresher = new FeedRefresher($storage, $fetcher, $this->get(LockFactory::class), $manager, $context, new NullLogger());
        $cache = $this->get(CacheManager::class)->getCache('feeddisplay');
        $provider = new FeedProvider($storage, $refresher, $fetcher, $this->get(FeedDataService::class), $cache, $context, new NullLogger());
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Persisted');
        $settings = ['feedUrl' => self::URL, 'cacheDuration' => 600, 'getFields' => ['feed' => 'title']];
        self::assertSame('Persisted', $provider->getData($settings)['feed']['title']);
        $state = $this->get(FeedStorage::class)->findState(self::URL);
        self::assertNotNull($state);
        self::assertTrue($state->invalidationPending);
        $cached = $cache->get('feeddisplay_' . md5(serialize($settings)));
        self::assertIsArray($cached);
        self::assertSame($state->revision, $cached['_feeddisplay_revision']);
        self::assertSame('Persisted', $provider->getData($settings)['feed']['title']);
        self::assertFalse($this->get(FeedStorage::class)->findState(self::URL)?->invalidationPending);
        self::assertSame(1, FeedHttp::countRequests(self::URL));
    }

    /** @return iterable<string, array{string}> */
    public static function readFailuresAfterPersistence(): iterable
    {
        yield 'exception' => ['exception'];
        yield 'missing state' => ['missing'];
    }

    #[Test]
    public function cacheHitsOnlyReadMetadataAndDoNotLogCorruptPayloads(): void
    {
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Large');
        $settings = ['feedUrl' => self::URL, 'cacheDuration' => 600, 'getFields' => ['feed' => 'title']];
        $this->get(FeedProvider::class)->getData($settings);
        $real = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE);
        $real->update(FeedStorage::TABLE, ['response_data' => 'corrupt'], ['url_hash' => FeedStorage::hashUrl(self::URL)]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(3))->method('select')->with(
            ['url', 'revision', 'fetched_at', 'last_attempt_at', 'last_error', 'invalidation_pending'],
            FeedStorage::TABLE,
            ['url_hash' => FeedStorage::hashUrl(self::URL)],
        )->willReturnCallback($real->select(...));
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $storage = new FeedStorage($pool, $logger);
        $provider = new FeedProvider($storage, $this->get(FeedRefresher::class), $this->get(FeedFetcher::class), $this->get(FeedDataService::class), $this->get(CacheManager::class)->getCache('feeddisplay'), $this->get(Context::class), $logger);
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('Large', $provider->getData($settings)['feed']['title']);
        }
    }
}
