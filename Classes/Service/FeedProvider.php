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
use TYPO3\CMS\Core\Context\Context;

/**
 * Decides at render time whether a feed has to be fetched, and returns the
 * response to render. A failed fetch falls back to the last stored response.
 *
 * @internal
 */
class FeedProvider
{
    /**
     * Recording every render would write on each uncached page view.
     */
    private const MARK_USED_INTERVAL = 3600;

    public function __construct(
        private readonly FeedStorage $storage,
        private readonly FeedRefresher $refresher,
        private readonly Context $context,
    ) {}

    /**
     * @param int $lifetime Seconds a stored response counts as fresh in live mode, 0 fetches on every call
     */
    public function provide(string $url, int $lifetime, FetchMode $mode = FetchMode::Live): ?StoredFeed
    {
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $storedFeed = $this->storage->find($url);

        if ($storedFeed === null || $storedFeed->lastUsedAt + self::MARK_USED_INTERVAL <= $now) {
            $this->storage->markUsed($url, $now);
        }

        if ($storedFeed !== null && $storedFeed->isFresh($now, $lifetime)) {
            return $storedFeed;
        }

        // In scheduler mode, page rendering only fetches a feed that has never been fetched successfully,
        // so the plugin does not stay empty until the first run of the refresh command.
        if ($mode === FetchMode::Scheduler && $storedFeed !== null && $storedFeed->hasContent()) {
            return $storedFeed;
        }

        // Without any stored response there is nothing to fall back to, so wait for a concurrent fetch.
        $result = $this->refresher->refresh($url, $storedFeed === null || !$storedFeed->hasContent());
        if ($result === RefreshResult::Locked) {
            return $storedFeed;
        }

        return $this->storage->find($url);
    }
}
