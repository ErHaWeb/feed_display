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

namespace ErHaWeb\FeedDisplay\Storage;

/** @internal */
final readonly class StoredFeed
{
    /** A successful revision exists; its payload may not yet be loaded or validated. */
    public bool $hasDocument;

    public function __construct(
        public string $url,
        public ?FeedDocument $document,
        public string $revision,
        public int $fetchedAt,
        public int $lastAttemptAt,
        public string $lastError,
        public bool $invalidationPending,
    ) {
        $this->hasDocument = $revision !== '';
    }
}
