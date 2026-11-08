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

namespace ErHaWeb\FeedDisplay\EventListener;

use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Frontend\Event\AfterCachedPageIsPersistedEvent;

/** @internal Discards pages whose source changed before their cache entry existed. */
#[AsEventListener(identifier: 'feed-display/verify-page-feed-revisions')]
final class VerifyPageFeedRevisions
{
    public function __construct(
        private readonly FeedStorage $storage,
        private readonly CacheManager $cacheManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(AfterCachedPageIsPersistedEvent $event): void
    {
        $collector = $event->getRequest()->getAttribute('frontend.cache.collector');
        if (!$collector instanceof CacheDataCollector) {
            return;
        }
        $revisions = [];
        foreach ($collector->getCacheTags() as $tag) {
            if (preg_match('/^feeddisplay_rev_([0-9a-f]{64})_([0-9a-f]{64})$/D', $tag->name, $match) === 1) {
                $revisions[$match[1]][$match[2]] = true;
            }
        }
        foreach ($revisions as $urlHash => $usedRevisions) {
            $context = ['urlHash' => $urlHash, 'cacheIdentifier' => $event->getCacheIdentifier()];
            try {
                $current = $this->storage->findRevisionByHash($urlHash);
            } catch (\Throwable $exception) {
                $this->logger->warning('Could not verify the cached feed revision.', $context + ['exception' => $exception]);
                continue;
            }
            if ($current !== null && count($usedRevisions) === 1 && isset($usedRevisions[$current])) {
                continue;
            }
            try {
                $this->cacheManager->getCache('pages')->remove($event->getCacheIdentifier());
            } catch (\Throwable $exception) {
                $this->logger->warning('Could not remove an obsolete feed page.', $context + ['exception' => $exception]);
            }
            return;
        }
    }
}
