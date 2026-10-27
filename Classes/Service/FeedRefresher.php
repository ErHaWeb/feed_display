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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * The single write path for feed responses, shared by frontend rendering and
 * the refresh command. A per-URL lock keeps concurrent fetches apart, and only
 * a successful fetch replaces the stored response.
 *
 * @internal
 */
class FeedRefresher implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly FeedFetcher $fetcher,
        private readonly FeedStorage $storage,
        private readonly LockFactory $lockFactory,
        private readonly Context $context,
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * @param bool $wait Wait for a concurrent fetch instead of returning RefreshResult::Locked
     */
    public function refresh(string $url, bool $wait = false): RefreshResult
    {
        $locker = $this->lockFactory->createLocker(
            'feed_display_' . FeedStorage::hashUrl($url),
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );

        try {
            $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE;
            $locker->acquire($wait ? $mode : $mode | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK);
        } catch (LockAcquireWouldBlockException) {
            return RefreshResult::Locked;
        }

        try {
            return $this->fetchAndStore($url);
        } finally {
            $locker->release();
        }
    }

    private function fetchAndStore(string $url): RefreshResult
    {
        $result = $this->fetcher->fetch($url);
        $now = $this->getNow();

        if (!$result->success) {
            $this->storage->storeFailure($url, $result->error, $now);
            $this->logger?->warning('Feed could not be fetched, keeping the last successful response.', [
                'url' => $url,
                'error' => $result->error,
            ]);

            return RefreshResult::Failed;
        }

        if (!$this->storage->storeSuccess($url, $result->rawData, $now)) {
            return RefreshResult::Unchanged;
        }

        // Pages showing this feed are rendered again with the new response.
        $this->cacheManager->flushCachesInGroupByTag('pages', FeedStorage::getPageCacheTag($url));

        return RefreshResult::Updated;
    }

    private function getNow(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }
}
