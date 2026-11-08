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
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/** @internal The shared write path for live, bootstrap and scheduled fetches. */
final class FeedRefresher
{
    public function __construct(
        private readonly FeedStorage $storage,
        private readonly FeedFetcher $fetcher,
        private readonly LockFactory $lockFactory,
        private readonly CacheManager $cacheManager,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {}

    public function refresh(string $url, bool $bounded = false): RefreshResult
    {
        return $this->withLock($url, 'refresh', function () use ($url, $bounded): RefreshResult {
            $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
            try {
                $fetched = $this->fetcher->fetch($url, $bounded);
            } catch (\Throwable $exception) {
                try {
                    $this->storage->storeFailure($url, $exception->getMessage(), $now);
                } catch (\Throwable $storageException) {
                    $this->logger->warning('Could not record feed fetch failure.', ['url' => $url, 'exception' => $storageException]);
                }
                throw $exception;
            }
            $persisted = false;
            try {
                $changed = $this->storage->storeSuccess($url, $fetched->document, $now);
                $persisted = true;
                $stored = $this->storage->findState($url);
                if ($stored !== null) {
                    $this->invalidate($stored);
                }
            } catch (\Throwable $exception) {
                // Keep the fresh parser usable for this request even when persistence fails.
                return new RefreshResult('failed', $fetched, $exception, $persisted);
            }
            return new RefreshResult($changed ? 'updated' : 'unchanged', $fetched, persisted: true);
        });
    }

    public function invalidate(StoredFeed $stored): void
    {
        if (!$stored->invalidationPending) {
            return;
        }
        $this->cacheManager->flushCachesInGroupByTag('pages', FeedStorage::cacheTag($stored->url));
        $this->storage->markInvalidated($stored->url, $stored->revision);
    }

    public function remove(string $url): RefreshResult
    {
        return $this->withLock($url, 'remove', function () use ($url): RefreshResult {
            $this->storage->remove($url);
            // Also retry this after a previous deletion whose invalidation failed.
            $this->cacheManager->flushCachesInGroupByTag('pages', FeedStorage::cacheTag($url));
            return new RefreshResult('updated');
        });
    }

    /** @param callable(): RefreshResult $operation */
    private function withLock(string $url, string $name, callable $operation): RefreshResult
    {
        $lock = null;
        $acquired = false;
        $result = new RefreshResult('locked');
        $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        try {
            $lock = $this->lockFactory->createLocker('feed_display_' . FeedStorage::hashUrl($url), $mode);
            try {
                $acquired = $lock->acquire($mode);
            } catch (LockAcquireWouldBlockException) {
                $acquired = false;
            }
            if ($acquired) {
                $result = $operation();
            }
        } catch (\Throwable $exception) {
            $result = new RefreshResult('failed', null, $exception);
        } finally {
            if ($lock !== null && $acquired) {
                try {
                    $lock->release();
                } catch (\Throwable $exception) {
                    $result = new RefreshResult('failed', $result->fetched, $exception, $result->persisted);
                }
            }
        }
        if ($result->error !== null) {
            $this->logger->warning('Feed source operation failed.', ['url' => $url, 'operation' => $name, 'exception' => $result->error]);
        }
        return $result;
    }
}
