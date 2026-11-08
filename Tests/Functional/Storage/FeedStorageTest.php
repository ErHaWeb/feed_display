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

use ErHaWeb\FeedDisplay\Service\FeedFetcher;
use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Service\FeedRuntimeInitializer;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Tests\Fixtures\StoredDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FeedStorageTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['erhaweb/feed-display'];
    private const URL = 'https://example.test/feed.xml';
    private const BODY = '<?xml version="1.0" encoding="ISO-8859-1"?><rss version="2.0"><channel><title>Stra\xDFe</title><description>Test</description></channel></rss>';

    #[Test]
    public function binaryDocumentSurvivesEveryCacheFlushAndFailedRefresh(): void
    {
        $storage = $this->get(FeedStorage::class);
        $document = StoredDocument::create("<rss>\x00Stra\xDFe\xff</rss>");
        self::assertTrue($storage->storeSuccess(self::URL, $document, 1000));
        $this->get(CacheManager::class)->flushCaches();
        $storage->storeFailure(self::URL, 'HTTP 503', 2000);
        $stored = $storage->find(self::URL);
        self::assertNotNull($stored);
        self::assertSame($document->payload, $stored->document?->payload);
        self::assertSame(1000, $stored->fetchedAt);
        self::assertSame(2000, $stored->lastAttemptAt);
        self::assertSame('HTTP 503', $stored->lastError);
    }

    #[Test]
    public function unchangedSuccessRenewsFreshnessAndPreservesPendingInvalidation(): void
    {
        $storage = $this->get(FeedStorage::class);
        $one = StoredDocument::create('one');
        $two = StoredDocument::create('two');
        $storage->storeSuccess(self::URL, $one, 1000);
        $storage->markInvalidated(self::URL, $one->revision());
        self::assertFalse($storage->storeSuccess(self::URL, $one, 2000));
        self::assertSame(2000, $storage->find(self::URL)?->fetchedAt);
        self::assertFalse($storage->find(self::URL)->invalidationPending);
        $storage->storeSuccess(self::URL, $two, 3000);
        $storage->markInvalidated(self::URL, $one->revision());
        self::assertTrue($storage->find(self::URL)?->invalidationPending);
        self::assertFalse($storage->storeSuccess(self::URL, $two, 4000));
        self::assertTrue($storage->find(self::URL)?->invalidationPending);
    }

    #[Test]
    public function unchangedRefreshRetriesAFailedPageInvalidation(): void
    {
        $calls = 0;
        $manager = $this->createMock(CacheManager::class);
        $manager->expects(self::exactly(2))->method('flushCachesInGroupByTag')->willReturnCallback(
            static function () use (&$calls): void {
                if (++$calls === 1) {
                    throw new \RuntimeException('Page cache temporarily unavailable');
                }
            }
        );
        $body = str_replace('Stra\\xDFe', "Stra\xDFe", self::BODY);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/rss+xml'], $body),
            new Response(200, ['Content-Type' => 'application/rss+xml'], $body),
        ]))]);
        $factory = self::createStub(GuzzleClientFactory::class);
        $factory->method('getClient')->willReturn($client);
        $http = new HttpFactory();
        $initializer = new FeedRuntimeInitializer($factory, $http, $http);
        $lock = self::createStub(LockingStrategyInterface::class);
        $lock->method('acquire')->willReturn(true);
        $locks = self::createStub(LockFactory::class);
        $locks->method('createLocker')->willReturn($lock);
        $context = new Context();
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@1000')));
        $storage = $this->get(FeedStorage::class);
        $subject = new FeedRefresher($storage, new FeedFetcher($initializer, $factory), $locks, $manager, $context, self::createStub(LoggerInterface::class));
        $first = $subject->refresh(self::URL);
        self::assertSame('failed', $first->status);
        self::assertTrue($first->persisted);
        self::assertTrue($storage->find(self::URL)?->invalidationPending);
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@2000')));
        self::assertSame('unchanged', $subject->refresh(self::URL)->status);
        self::assertFalse($storage->find(self::URL)?->invalidationPending);
        self::assertSame(2000, $storage->find(self::URL)->fetchedAt);
    }
    #[Test]
    public function lazyReadsDoNotReturnAPayloadFromADifferentRevision(): void
    {
        $storage = $this->get(FeedStorage::class);
        $one = StoredDocument::create("one\xFF\x00");
        $two = StoredDocument::create("two\xDF\x00");
        $storage->storeSuccess(self::URL, $one, 1000);
        $state = $storage->findState(self::URL);
        self::assertNotNull($state);
        self::assertTrue($state->hasDocument);
        self::assertNull($state->document);
        self::assertSame($one->payload, $storage->loadDocument(self::URL, $state->revision)?->payload);
        $storage->storeSuccess(self::URL, $two, 2000);
        self::assertNull($storage->loadDocument(self::URL, $state->revision));
        $current = $storage->findState(self::URL);
        self::assertNotNull($current);
        self::assertSame($two->payload, $storage->loadDocument(self::URL, $current->revision)?->payload);
    }

}
