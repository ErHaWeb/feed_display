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

namespace ErHaWeb\FeedDisplay\Controller;

use ErHaWeb\FeedDisplay\Service\FeedDataService;
use ErHaWeb\FeedDisplay\Service\FeedProvider;
use ErHaWeb\FeedDisplay\Service\FetchMode;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Storage\StoredFeed;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class FeedController extends ActionController
{
    public const CACHE_TAG = 'feeddisplay';

    public function __construct(
        private readonly FrontendInterface $cache,
        private readonly FeedDataService $feedDataService,
        private readonly FeedProvider $feedProvider,
    ) {}

    public function displayAction(): ResponseInterface
    {
        if ($this->settings === []) {
            return $this->htmlResponse();
        }

        $cacheDuration = (int)($this->settings['cacheDuration'] ?? 0);
        $feedUrl = stripslashes((string)($this->settings['feedUrl'] ?? ''));
        $fetchMode = FetchMode::fromSetting($this->settings['fetchMode'] ?? null);
        $storedFeed = null;
        if ($feedUrl !== '') {
            $storedFeed = $this->feedProvider->provide($feedUrl, $cacheDuration, $fetchMode);
            $this->tagPageCache($feedUrl, $fetchMode, $cacheDuration);
        }

        $data = $storedFeed !== null && $storedFeed->hasContent()
            ? $this->getFeedData($storedFeed, $cacheDuration)
            : ['settings' => $this->settings];

        $this->view->assign('data', $data);

        return $this->htmlResponse();
    }

    /**
     * Lets FeedRefresher flush the page when the feed changes. In live mode, the
     * page is also cached no longer than cacheDuration, so it fetches again in time.
     */
    private function tagPageCache(string $feedUrl, FetchMode $fetchMode, int $cacheDuration): void
    {
        $cacheDataCollector = $this->request->getAttribute('frontend.cache.collector');
        if (!$cacheDataCollector instanceof CacheDataCollector) {
            return;
        }

        $lifetime = $fetchMode === FetchMode::Live && $cacheDuration > 0 ? $cacheDuration : PHP_INT_MAX;
        $cacheDataCollector->addCacheTags(new CacheTag(FeedStorage::getPageCacheTag($feedUrl), $lifetime));
    }

    /**
     * @return array<string, mixed>
     */
    private function getFeedData(StoredFeed $storedFeed, int $cacheDuration): array
    {
        // Each configuration gets its own entry, rebuilt when the stored response changes.
        $cacheIdentifier = 'feeddisplay_' . md5(serialize($this->settings));

        if ($cacheDuration === 0) {
            $this->cache->remove($cacheIdentifier);

            return $this->feedDataService->buildData($this->settings, $storedFeed->rawBody);
        }

        $entry = $this->cache->get($cacheIdentifier);
        if (is_array($entry) && ($entry['bodyHash'] ?? null) === $storedFeed->bodyHash && is_array($entry['data'] ?? null)) {
            return $entry['data'];
        }

        $data = $this->feedDataService->buildData($this->settings, $storedFeed->rawBody);
        $this->cache->set(
            $cacheIdentifier,
            ['bodyHash' => $storedFeed->bodyHash, 'data' => $data],
            [self::CACHE_TAG, FeedStorage::getPageCacheTag($storedFeed->url)],
            $cacheDuration
        );

        return $data;
    }
}
