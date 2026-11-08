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

namespace ErHaWeb\FeedDisplay\Service;

use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Storage\StoredFeed;
use Psr\Log\LoggerInterface;
use SimplePie\SimplePie;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;

/** @internal Combines durable source freshness with disposable per-plugin data. */
final class FeedProvider
{
    private const REVISION = '_feeddisplay_revision';

    public function __construct(
        private readonly FeedStorage $storage,
        private readonly FeedRefresher $refresher,
        private readonly FeedFetcher $fetcher,
        private readonly FeedDataService $dataService,
        #[Autowire(service: 'cache.feeddisplay')]
        private readonly FrontendInterface $cache,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function getData(array $settings, bool $scheduler = false): array
    {
        $url = trim(stripslashes((string)($settings['feedUrl'] ?? '')));
        $duration = (int)($settings['cacheDuration'] ?? 0);
        $identifier = 'feeddisplay_' . md5(serialize($settings));
        $cached = $this->readCache($identifier, $settings);
        if ($url === '') {
            return ['settings' => $settings];
        }
        if (!$scheduler && $duration > 0 && $cached !== false
            && is_string($cached[self::REVISION] ?? null) && str_starts_with($cached[self::REVISION], 'unstored:')
        ) {
            // Persistence failed: retain the successful display result for its original TTL.
            return $this->displayData($cached);
        }
        try {
            $stored = $this->storage->findState($url);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not read the persistent feed. Apply the extension database schema.', ['url' => $url, 'exception' => $exception]);
            if (!$scheduler && $duration > 0 && $cached !== false) {
                return $this->displayData($cached);
            }
            $fetched = $scheduler ? null : $this->fetchWithoutStorage($url);
            if ($fetched === null) {
                return $this->cacheData($identifier, ['settings' => $settings], '', $duration);
            }
            return $this->fetchedData($identifier, $fetched, $settings, $duration, $scheduler);
        }
        // A pre-upgrade cache hit remains usable until its original TTL expires.
        if (!$scheduler && $duration > 0 && $cached !== false
            && !array_key_exists(self::REVISION, $cached) && !($stored->hasDocument ?? false)
        ) {
            return $cached;
        }
        if ($stored !== null && $stored->invalidationPending) {
            try {
                $this->refresher->invalidate($stored);
            } catch (\Throwable $exception) {
                $this->logger->warning('Could not invalidate feed pages; the operation remains pending.', ['url' => $url, 'exception' => $exception]);
            }
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $lastAttempt = $stored->lastAttemptAt ?? 0;
        $needsFetch = $scheduler
            ? !($stored->hasDocument ?? false) && ($lastAttempt === 0 || $lastAttempt + 60 <= $now)
            : $duration <= 0 || $lastAttempt === 0 || $lastAttempt + $duration <= $now;
        $fetched = null;
        $attempted = false;
        // The second pass repairs an unusable stored document, at most once per request.
        while (true) {
            if ($needsFetch) {
                $attempted = true;
                $result = $this->refresher->refresh($url, $scheduler);
                $fetched = $result->fetched;
                if ($result->status === 'failed' && $fetched !== null && !$result->persisted) {
                    return $this->fetchedData($identifier, $fetched, $settings, $duration, $scheduler);
                }
                try {
                    // Re-read after a busy lock, too: another worker may have finished.
                    $stored = $this->storage->findState($url);
                } catch (\Throwable $exception) {
                    $this->logger->warning('Could not read the refreshed feed.', ['url' => $url, 'exception' => $exception]);
                    if ($fetched !== null) {
                        return $this->fetchedData($identifier, $fetched, $settings, $duration, $scheduler, $result->persisted);
                    }
                    $stored = null;
                }
                if ($fetched !== null && !($stored->hasDocument ?? false)) {
                    return $this->fetchedData($identifier, $fetched, $settings, $duration, $scheduler, $result->persisted);
                }
                if (!$scheduler && $result->status === 'locked' && !($stored->hasDocument ?? false)) {
                    $fresh = $this->fetchWithoutStorage($url);
                    if ($fresh !== null) {
                        return $this->dataService->buildInitializedData($fresh->feed, $settings);
                    }
                }
            }
            if ($duration > 0 && $cached !== false && $stored !== null
                && $stored->hasDocument && ($cached[self::REVISION] ?? null) === $stored->revision
            ) {
                return $this->displayData($cached);
            }
            $feed = $this->restoreFeed($url, $stored, $fetched);
            if ($feed !== null && $stored !== null) {
                // Public events and getters remain outside transport/storage catches.
                $data = $this->dataService->buildInitializedData($feed, $settings);
                return $this->cacheData($identifier, $data, $stored->revision, $duration);
            }
            $lastAttempt = $stored->lastAttemptAt ?? 0;
            if ($attempted || !($stored->hasDocument ?? false) || ($lastAttempt !== 0 && $lastAttempt + 60 > $now)) {
                break;
            }
            // A successful revision whose payload cannot be used follows bootstrap retry rules.
            $needsFetch = true;
        }
        if (!$scheduler && $duration > 0 && $cached !== false && (isset($cached['feed']) || isset($cached['items']))) {
            return $this->displayData($cached);
        }
        return $this->cacheData($identifier, ['settings' => $settings], '', $duration);
    }

    private function restoreFeed(string $url, ?StoredFeed &$stored, ?FetchedFeed $fetched): ?SimplePie
    {
        try {
            // Retry a concurrently replaced revision once, never spin in the frontend.
            for ($attempt = 0; $attempt < 2 && $stored !== null && $stored->hasDocument; ++$attempt) {
                if ($fetched !== null && $fetched->document->revision() === $stored->revision) {
                    return $fetched->feed;
                }
                $document = $this->storage->loadDocument($url, $stored->revision);
                if ($document !== null) {
                    return $this->fetcher->restore($document);
                }
                $current = $this->storage->findState($url);
                if ($current?->revision === $stored->revision) {
                    return null;
                }
                $stored = $current;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not replay the persistent feed.', ['url' => $url, 'exception' => $exception]);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function fetchedData(string $identifier, FetchedFeed $fetched, array $settings, int $duration, bool $scheduler, bool $persisted = false): array
    {
        $data = $this->dataService->buildInitializedData($fetched->feed, $settings);
        $revision = $fetched->document->revision();
        $cacheRevision = $persisted ? $revision : 'unstored:' . $revision;
        return $scheduler ? $data : $this->cacheData($identifier, $data, $cacheRevision, $duration);
    }

    private function fetchWithoutStorage(string $url): ?FetchedFeed
    {
        try {
            return $this->fetcher->fetch($url);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not fetch the live feed.', ['url' => $url, 'exception' => $exception]);
            return null;
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|false
     */
    private function readCache(string $identifier, array $settings): array|false
    {
        try {
            $cached = $this->cache->get($identifier);
            return is_array($cached) && ($cached['settings'] ?? null) === $settings ? $cached : false;
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not read the feed display cache.', ['exception' => $exception]);
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cacheData(string $identifier, array $data, string $revision, int $duration): array
    {
        try {
            if ($duration > 0) {
                $this->cache->set($identifier, $data + [self::REVISION => $revision], [], $duration);
            } else {
                $this->cache->remove($identifier);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not update the feed display cache.', ['exception' => $exception]);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed>
     */
    private function displayData(array $cached): array
    {
        unset($cached[self::REVISION]);
        return $cached;
    }
}
