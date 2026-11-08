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

use Doctrine\DBAL\Result;
use ErHaWeb\FeedDisplay\Service\FeedDataService;
use ErHaWeb\FeedDisplay\Service\FeedFetcher;
use ErHaWeb\FeedDisplay\Service\FeedProvider;
use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Service\FeedRuntimeInitializer;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Tests\Fixtures\StoredDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FeedFailureTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['erhaweb/feed-display'];

    private function source(): string
    {
        $path = $this->instancePath . '/new.xml';
        file_put_contents($path, '<rss version="2.0"><channel><title>Fresh</title><description>Test</description><item><title>Item</title></item></channel></rss>');
        return $path;
    }

    #[Test]
    public function failedDatabaseWritePreservesTheOldDocumentAndReturnsTheFreshParser(): void
    {
        $url = $this->source();
        $storage = $this->get(FeedStorage::class);
        $original = StoredDocument::create('Old response');
        $storage->storeSuccess($url, $original, 1000);
        $row = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->select(['*'], FeedStorage::TABLE)->fetchAssociative();
        $result = self::createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);
        $connection = $this->createMock(Connection::class);
        $connection->method('select')->willReturn($result);
        $connection->expects(self::once())->method('update')->willThrowException(new \RuntimeException('Simulated write failure'));
        $connection->expects(self::never())->method('delete');
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $failing = new FeedStorage($pool, new NullLogger());
        $refresher = new FeedRefresher($failing, $this->get(FeedFetcher::class), $this->get(LockFactory::class), $this->get(CacheManager::class), $this->get(Context::class), new NullLogger());
        $refreshed = $refresher->refresh($url);
        self::assertSame('failed', $refreshed->status);
        self::assertSame('Fresh', \ErHaWeb\FeedDisplay\Service\SimplePieDeprecationHandler::run(static fn(): ?string => $refreshed->fetched?->feed->get_title()));
        self::assertSame($original->revision(), $storage->find($url)?->revision);
        self::assertSame(1000, $storage->find($url)->fetchedAt);
    }

    #[Test]
    public function corruptDocumentsProduceADiagnosis(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Could not decode the stored feed.', self::callback(static fn(array $context): bool => $context['url'] === 'broken'));
        $storage = new FeedStorage($this->getConnectionPool(), $logger);
        $storage->storeSuccess('broken', StoredDocument::create('Original'), 1000);
        $bytes = serialize(['version' => 2, 'kind' => 'snapshot', 'snapshot' => ['response' => ['uri' => 'https://example.test', 'base' => 'https://example.test', 'status' => 200, 'body' => 'Body', 'headers' => ['Bad Header' => ['x']]], 'mime' => 'text/xml']]);
        $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->update(FeedStorage::TABLE, ['response_data' => $bytes, 'revision' => hash('sha256', $bytes)], ['url_hash' => FeedStorage::hashUrl('broken')]);
        self::assertNull($storage->find('broken')?->document);
    }

    #[Test]
    #[DataProvider('lockFailures')]
    public function lockFailuresAreDiagnosedAndBusySourcesAreSkipped(string $stage, string $expected): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($expected === 'failed' ? self::once() : self::never())->method('warning');
        $url = $this->source();
        $locks = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockingStrategyInterface::class);
        if ($stage === 'create') {
            $locks->method('createLocker')->willThrowException(new \RuntimeException('Create failed'));
        } else {
            $locks->method('createLocker')->willReturn($lock);
        }
        if ($stage === 'acquire' || $stage === 'would-block') {
            $lock->method('acquire')->willThrowException($stage === 'acquire' ? new \RuntimeException('Acquire failed') : new LockAcquireWouldBlockException('Busy'));
        } else {
            $lock->method('acquire')->willReturn($stage !== 'busy');
        }
        if ($stage === 'release') {
            $lock->expects(self::once())->method('release')->willThrowException(new \RuntimeException('Release failed'));
        } else {
            $lock->expects(self::never())->method('release');
        }
        $refresher = new FeedRefresher($this->get(FeedStorage::class), $this->get(FeedFetcher::class), $locks, $this->get(CacheManager::class), $this->get(Context::class), $logger);
        self::assertSame($expected, $refresher->refresh($url)->status);
    }

    /** @return iterable<string, array{string, string}> */
    public static function lockFailures(): iterable
    {
        yield 'creation' => ['create', 'failed'];
        yield 'acquisition' => ['acquire', 'failed'];
        yield 'release' => ['release', 'failed'];
        yield 'busy return' => ['busy', 'locked'];
        yield 'busy exception' => ['would-block', 'locked'];
    }

    #[Test]
    public function publicEventFailuresAreNotSwallowedByTheProvider(): void
    {
        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \DomainException('Listener failed'));
        $data = new FeedDataService(new SimplePie(), $dispatcher, $this->get(FeedRuntimeInitializer::class));
        $provider = new FeedProvider($this->get(FeedStorage::class), $this->get(FeedRefresher::class), $this->get(FeedFetcher::class), $data, $this->get(CacheManager::class)->getCache('feeddisplay'), $this->get(Context::class), new NullLogger());
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Listener failed');
        $provider->getData(['feedUrl' => $this->source(), 'cacheDuration' => 0, 'getFields' => ['items' => 'title']]);
    }
    #[Test]
    public function concurrentRevisionIsNotLabelledWithAnEarlierParsersData(): void
    {
        $url = $this->source();
        $storage = $this->get(FeedStorage::class);
        $next = StoredDocument::create('<rss version="2.0"><channel><title>Concurrent result</title><description>Test</description></channel></rss>');
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->method('release')->willReturnCallback(static function () use ($storage, $url, $next): bool {
            // Model a second writer completing after our lock release, before re-reading.
            $storage->storeSuccess($url, $next, time());
            return true;
        });
        $locks = self::createStub(LockFactory::class);
        $locks->method('createLocker')->willReturn($lock);
        $cache = $this->get(CacheManager::class)->getCache('feeddisplay');
        $fetcher = $this->get(FeedFetcher::class);
        $context = $this->get(Context::class);
        $refresher = new FeedRefresher($storage, $fetcher, $locks, $this->get(CacheManager::class), $context, new NullLogger());
        $provider = new FeedProvider($storage, $refresher, $fetcher, $this->get(FeedDataService::class), $cache, $context, new NullLogger());
        $settings = ['feedUrl' => $url, 'cacheDuration' => 600, 'getFields' => ['feed' => 'title']];
        $data = $provider->getData($settings);
        self::assertSame('Concurrent result', $data['feed']['title']);
        self::assertArrayNotHasKey('_feeddisplay_revision', $data);
        $cached = $cache->get('feeddisplay_' . md5(serialize($settings)));
        self::assertIsArray($cached);
        self::assertSame('Concurrent result', $cached['feed']['title']);
        self::assertSame($next->revision(), $cached['_feeddisplay_revision']);
    }

    #[Test]
    public function concurrentReplacementDuringLazyLoadingIsRetriedWithItsOwnRevision(): void
    {
        $url = $this->source();
        $storage = $this->get(FeedStorage::class);
        $fetcher = $this->get(FeedFetcher::class);
        $storage->storeSuccess($url, $fetcher->fetch($url)->document, time());
        $next = StoredDocument::create('<rss version="2.0"><channel><title>Concurrent lazy result</title><description>Test</description></channel></rss>');
        $real = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE);
        $swapped = false;
        $connection = self::createStub(Connection::class);
        $connection->method('select')->willReturnCallback(static function (array $columns, string $table, array $where = []) use ($storage, $url, $next, $real, &$swapped): Result {
            if ($columns === ['response_data'] && !$swapped) {
                $swapped = true;
                $storage->storeSuccess($url, $next, time());
            }
            return $real->select($columns, $table, $where);
        });
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        $lazy = new FeedStorage($pool, new NullLogger());
        $cache = $this->get(CacheManager::class)->getCache('feeddisplay');
        $provider = new FeedProvider($lazy, $this->get(FeedRefresher::class), $fetcher, $this->get(FeedDataService::class), $cache, $this->get(Context::class), new NullLogger());
        $settings = ['feedUrl' => $url, 'cacheDuration' => 600, 'getFields' => ['feed' => 'title']];
        self::assertSame('Concurrent lazy result', $provider->getData($settings)['feed']['title']);
        $cached = $cache->get('feeddisplay_' . md5(serialize($settings)));
        self::assertIsArray($cached);
        self::assertSame($next->revision(), $cached['_feeddisplay_revision']);
    }

}
