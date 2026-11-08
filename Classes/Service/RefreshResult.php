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

/** @internal */
final readonly class RefreshResult
{
    /** @param 'updated'|'unchanged'|'locked'|'failed' $status */
    public function __construct(
        public string $status,
        public ?FetchedFeed $fetched = null,
        public ?\Throwable $error = null,
        public bool $persisted = false,
    ) {}
}
