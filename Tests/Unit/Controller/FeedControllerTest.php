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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Controller;

use ErHaWeb\FeedDisplay\Controller\FeedController;
use ErHaWeb\FeedDisplay\Service\FeedDataService;
use ErHaWeb\FeedDisplay\Service\FeedProvider;
use ErHaWeb\FeedDisplay\Service\FetchMode;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Storage\StoredFeed;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedControllerTest extends UnitTestCase
{
    private const FEED_URL = 'https://example.com/feed.xml';

    #[Test]
    public function displayActionReturnsResponseWithoutTouchingCacheIfSettingsAreEmpty(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $feedProvider = $this->createMock(FeedProvider::class);

        $cache->expects(self::never())->method('get');
        $feedDataService->expects(self::never())->method('buildData');
        $feedProvider->expects(self::never())->method('provide');

        $subject = new TestableFeedController($cache, $feedDataService, $feedProvider);
        $subject->setSettings([]);
        $subject->setView(new RecordingView());

        $subject->displayAction();
    }

    #[Test]
    public function displayActionAssignsOnlySettingsIfFeedUrlIsEmpty(): void
    {
        $settings = ['feedUrl' => '', 'cacheDuration' => 600];
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $feedProvider = $this->createMock(FeedProvider::class);
        $view = new RecordingView();

        $feedProvider->expects(self::never())->method('provide');
        $feedDataService->expects(self::never())->method('buildData');

        $subject = new TestableFeedController($cache, $feedDataService, $feedProvider);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame(['settings' => $settings], $view->assigned['data']);
    }

    #[Test]
    public function displayActionAssignsOnlySettingsIfFeedWasNeverFetchedSuccessfully(): void
    {
        $settings = ['feedUrl' => self::FEED_URL, 'cacheDuration' => 600];
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $feedProvider = $this->createMock(FeedProvider::class);
        $view = new RecordingView();

        $feedProvider->expects(self::once())->method('provide')->with(self::FEED_URL, 600, FetchMode::Live)
            ->willReturn(new StoredFeed(self::FEED_URL, lastError: 'Not found'));
        $feedDataService->expects(self::never())->method('buildData');

        $subject = new TestableFeedController($cache, $feedDataService, $feedProvider);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame(['settings' => $settings], $view->assigned['data']);
    }

    #[Test]
    public function displayActionPassesTheConfiguredFetchModeToTheProvider(): void
    {
        $settings = ['feedUrl' => self::FEED_URL, 'cacheDuration' => 600, 'fetchMode' => 'scheduler'];
        $feedProvider = $this->createMock(FeedProvider::class);

        $feedProvider->expects(self::once())->method('provide')->with(self::FEED_URL, 600, FetchMode::Scheduler)->willReturn(null);

        $subject = new TestableFeedController(
            $this->createMock(FrontendInterface::class),
            $this->createMock(FeedDataService::class),
            $feedProvider
        );
        $subject->setSettings($settings);
        $subject->setView(new RecordingView());
        $subject->displayAction();
    }

    #[Test]
    public function displayActionBypassesCacheIfCacheDurationIsZero(): void
    {
        $settings = ['feedUrl' => self::FEED_URL, 'cacheDuration' => 0];
        $data = ['settings' => $settings, 'items' => [['title' => 'First item']]];
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $feedProvider = $this->createMock(FeedProvider::class);
        $view = new RecordingView();

        $feedProvider->method('provide')->willReturn($this->createStoredFeed('<rss/>'));
        $cache->expects(self::never())->method('get');
        $cache->expects(self::once())->method('remove');
        $cache->expects(self::never())->method('set');
        $feedDataService->expects(self::once())->method('buildData')->with($settings, '<rss/>')->willReturn($data);

        $subject = new TestableFeedController($cache, $feedDataService, $feedProvider);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($data, $view->assigned['data']);
    }

    #[Test]
    public function displayActionCachesBuiltDataPerSettingsOnCacheMiss(): void
    {
        $settings = ['feedUrl' => self::FEED_URL, 'cacheDuration' => 600];
        $data = ['settings' => $settings, 'items' => [['title' => 'Cached item']]];
        $storedFeed = $this->createStoredFeed('<rss/>');
        $cacheIdentifier = 'feeddisplay_' . md5(serialize($settings));
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $feedProvider = $this->createMock(FeedProvider::class);
        $view = new RecordingView();

        $feedProvider->method('provide')->willReturn($storedFeed);
        $cache->expects(self::once())->method('get')->with($cacheIdentifier)->willReturn(false);
        $cache->expects(self::once())->method('set')->with(
            $cacheIdentifier,
            ['bodyHash' => $storedFeed->bodyHash, 'data' => $data],
            ['feeddisplay', 'feeddisplay_' . FeedStorage::hashUrl(self::FEED_URL)],
            600
        );
        $feedDataService->expects(self::once())->method('buildData')->with($settings, '<rss/>')->willReturn($data);

        $subject = new TestableFeedController($cache, $feedDataService, $feedProvider);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($data, $view->assigned['data']);
    }

    #[Test]
    public function displayActionReusesCachedDataWhileStoredResponseIsUnchanged(): void
    {
        $settings = ['feedUrl' => self::FEED_URL, 'cacheDuration' => 600];
        $cachedData = ['settings' => $settings, 'items' => [['title' => 'Cached item']]];
        $storedFeed = $this->createStoredFeed('<rss/>');
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $feedProvider = $this->createMock(FeedProvider::class);
        $view = new RecordingView();

        $feedProvider->method('provide')->willReturn($storedFeed);
        $cache->expects(self::once())->method('get')->willReturn(['bodyHash' => $storedFeed->bodyHash, 'data' => $cachedData]);
        $cache->expects(self::never())->method('set');
        $feedDataService->expects(self::never())->method('buildData');

        $subject = new TestableFeedController($cache, $feedDataService, $feedProvider);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($cachedData, $view->assigned['data']);
    }

    #[Test]
    public function displayActionRebuildsCachedDataWhenStoredResponseChanged(): void
    {
        $settings = ['feedUrl' => self::FEED_URL, 'cacheDuration' => 600];
        $rebuiltData = ['settings' => $settings, 'items' => [['title' => 'Fresh item']]];
        $storedFeed = $this->createStoredFeed('<rss>new</rss>');
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $feedProvider = $this->createMock(FeedProvider::class);
        $view = new RecordingView();

        $feedProvider->method('provide')->willReturn($storedFeed);
        $cache->expects(self::once())->method('get')->willReturn([
            'bodyHash' => hash('sha256', '<rss>old</rss>'),
            'data' => ['settings' => $settings, 'items' => [['title' => 'Outdated item']]],
        ]);
        $cache->expects(self::once())->method('set');
        $feedDataService->expects(self::once())->method('buildData')->with($settings, '<rss>new</rss>')->willReturn($rebuiltData);

        $subject = new TestableFeedController($cache, $feedDataService, $feedProvider);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($rebuiltData, $view->assigned['data']);
    }

    #[Test]
    public function liveModeLimitsThePageCacheLifetimeToTheCacheDuration(): void
    {
        $cacheDataCollector = $this->renderWithCacheDataCollector(['feedUrl' => self::FEED_URL, 'cacheDuration' => 600]);

        self::assertEquals(
            [new CacheTag(FeedStorage::getPageCacheTag(self::FEED_URL), 600)],
            $cacheDataCollector->getCacheTags()
        );
        self::assertSame(600, $cacheDataCollector->resolveLifetime());
    }

    #[Test]
    public function schedulerModeTagsThePageWithoutLimitingItsLifetime(): void
    {
        $cacheDataCollector = $this->renderWithCacheDataCollector(
            ['feedUrl' => self::FEED_URL, 'cacheDuration' => 600, 'fetchMode' => 'scheduler']
        );

        self::assertEquals(
            [new CacheTag(FeedStorage::getPageCacheTag(self::FEED_URL))],
            $cacheDataCollector->getCacheTags()
        );
        self::assertSame(PHP_INT_MAX, $cacheDataCollector->resolveLifetime());
    }

    #[Test]
    public function emptyFeedUrlDoesNotTagThePage(): void
    {
        $cacheDataCollector = $this->renderWithCacheDataCollector(['feedUrl' => '', 'cacheDuration' => 600]);

        self::assertSame([], $cacheDataCollector->getCacheTags());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function renderWithCacheDataCollector(array $settings): CacheDataCollector
    {
        $cacheDataCollector = new CacheDataCollector();
        $subject = new TestableFeedController(
            $this->createMock(FrontendInterface::class),
            $this->createMock(FeedDataService::class),
            $this->createMock(FeedProvider::class),
            $cacheDataCollector
        );
        $subject->setSettings($settings);
        $subject->setView(new RecordingView());
        $subject->displayAction();

        return $cacheDataCollector;
    }

    private function createStoredFeed(string $rawBody): StoredFeed
    {
        return new StoredFeed(self::FEED_URL, $rawBody, hash('sha256', $rawBody), 1000, 1000);
    }
}

final class TestableFeedController extends FeedController
{
    public function __construct(
        FrontendInterface $cache,
        FeedDataService $feedDataService,
        FeedProvider $feedProvider,
        ?CacheDataCollector $cacheDataCollector = null,
    ) {
        parent::__construct($cache, $feedDataService, $feedProvider);
        $serverRequest = (new ServerRequest())->withAttribute('extbase', new ExtbaseRequestParameters());
        if ($cacheDataCollector !== null) {
            $serverRequest = $serverRequest->withAttribute('frontend.cache.collector', $cacheDataCollector);
        }
        $this->request = new Request($serverRequest);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function setView(ViewInterface $view): void
    {
        $this->view = $view;
    }

    protected function htmlResponse(?string $html = null): ResponseInterface
    {
        return new HtmlResponse($html ?? '');
    }
}

final class RecordingView implements ViewInterface
{
    /** @var array<string, mixed> */
    public array $assigned = [];

    public function assign(string $key, mixed $value): self
    {
        $this->assigned[$key] = $value;
        return $this;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function assignMultiple(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->assigned[$key] = $value;
        }
        return $this;
    }

    public function render(string $templateFileName = ''): string
    {
        return '';
    }

    /**
     * @param array<array-key, mixed> $variables
     */
    public function renderSection(string $sectionName, array $variables = [], bool $ignoreUnknown = false): string
    {
        return '';
    }

    /**
     * @param array<array-key, mixed> $variables
     */
    public function renderPartial(string $partialName, string $sectionName, array $variables, bool $ignoreUnknown = false): string
    {
        return '';
    }
}
