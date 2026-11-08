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

use ErHaWeb\FeedDisplay\Service\FeedProvider;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Tests\Fixtures\StoredDocument;
use ErHaWeb\FeedDisplay\Tests\Functional\Fixtures\FeedHttp;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;

final class ScheduledHttpFeedTest extends AbstractHttpFeedTestCase
{
    #[Test]
    public function aLiveRefreshInvalidatesSchedulerPagesOfTheSameSource(): void
    {
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Original');
        $this->initializeFrontendRootPage(self::URL, ['fetchMode' => 'scheduler']);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, 'config.no_cache = 0');
        self::assertStringContainsString('Original', $this->requestPage());
        $backend = $this->get(CacheManager::class)->getCache('pages')->getBackend();
        self::assertInstanceOf(TaggableBackendInterface::class, $backend);
        self::assertCount(1, $backend->findIdentifiersByTag(FeedStorage::cacheTag(self::URL)));
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Live change');
        $this->get(FeedProvider::class)->getData(['feedUrl' => self::URL, 'cacheDuration' => 0, 'getFields' => ['feed' => 'title']]);
        self::assertSame([], $backend->findIdentifiersByTag(FeedStorage::cacheTag(self::URL)));
        self::assertStringContainsString('Live change', $this->requestPage());
        self::assertSame(2, FeedHttp::countRequests(self::URL));
    }

    #[Test]
    public function unreplayableDocumentIsRepairedOnce(): void
    {
        $this->storeHttpDocument(self::URL, 'No XML feed');
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Repaired');
        $this->initializeFrontendRootPage(self::URL, ['fetchMode' => 'scheduler', 'cacheDuration' => 600]);
        self::assertStringContainsString('Repaired', $this->requestPage());
        self::assertStringContainsString('Repaired', $this->requestPage());
        self::assertSame(1, FeedHttp::countRequests(self::URL));
        self::assertGreaterThan(1, $this->get(FeedStorage::class)->findState(self::URL)?->lastAttemptAt);
    }

    #[Test]
    public function failedRepairIsThrottledAndDoesNotReplaceTheStoredResponse(): void
    {
        $this->storeHttpDocument(self::URL, 'No XML feed');
        $storage = $this->get(FeedStorage::class);
        $before = $storage->findState(self::URL);
        $this->initializeFrontendRootPage(self::URL, ['fetchMode' => 'scheduler', 'cacheDuration' => 0]);
        self::assertStringContainsString('error-no-feed', $this->requestPage());
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Available later');
        self::assertStringContainsString('error-no-feed', $this->requestPage());
        self::assertSame(1, FeedHttp::countRequests(self::URL));
        self::assertSame($before?->revision, $storage->findState(self::URL)?->revision);
        self::assertSame($before?->fetchedAt, $storage->findState(self::URL)?->fetchedAt);
        $now = (int)$this->get(Context::class)->getPropertyFromAspect('date', 'timestamp');
        $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->update(FeedStorage::TABLE, ['last_attempt_at' => $now - 61], ['url_hash' => FeedStorage::hashUrl(self::URL)]);
        self::assertStringContainsString('Available later', $this->requestPage());
        self::assertSame(2, FeedHttp::countRequests(self::URL));
    }

    #[Test]
    public function repeatedSchedulerRenderingsReuseDownloadedImages(): void
    {
        $image = 'https://image.example.test/logo.png';
        FeedHttp::$responses[self::URL] = FeedHttp::rss('Image', '<image><url>' . $image . '</url><title>Image</title><link>https://example.test/</link></image>');
        FeedHttp::$responses[$image] = new Response(200, ['Content-Type' => 'image/png'], 'PNGDATA');
        $this->initializeFrontendRootPage(self::URL, ['fetchMode' => 'scheduler', 'cacheDuration' => 0]);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, 'plugin.tx_feeddisplay_pi1.settings.getFields.feed = title,image_url');
        for ($request = 0; $request < 3; ++$request) {
            self::assertStringContainsString('Image', $this->requestPage());
        }
        self::assertSame(1, FeedHttp::countRequests(self::URL));
        self::assertSame(1, FeedHttp::countRequests($image));
    }

    #[Test]
    public function repairSkipsABusySourceAndRetriesAfterRelease(): void
    {
        $this->storeHttpDocument(self::URL, 'No XML feed');
        FeedHttp::$responses[self::URL] = FeedHttp::rss('After lock');
        $this->initializeFrontendRootPage(self::URL, ['fetchMode' => 'scheduler', 'cacheDuration' => 0]);
        $mode = \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $lock = $this->get(\TYPO3\CMS\Core\Locking\LockFactory::class)->createLocker('feed_display_' . FeedStorage::hashUrl(self::URL), $mode);
        self::assertTrue($lock->acquire($mode));
        try {
            self::assertStringContainsString('error-no-feed', $this->requestPage());
            self::assertSame([], FeedHttp::$requests);
        } finally {
            $lock->release();
        }
        self::assertStringContainsString('After lock', $this->requestPage());
        self::assertSame(1, FeedHttp::countRequests(self::URL));
    }

    private function storeHttpDocument(string $uri, string $body): void
    {
        $document = StoredDocument::create($body, $uri);
        $storage = $this->get(FeedStorage::class);
        $storage->storeSuccess(self::URL, $document, 1);
        $storage->markInvalidated(self::URL, $document->revision());
    }
}
