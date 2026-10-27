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

/**
 * Last known state of a feed URL, as persisted in tx_feeddisplay_feed.
 *
 * @internal
 */
final readonly class StoredFeed
{
    public function __construct(
        public string $url,
        public string $rawBody = '',
        public string $bodyHash = '',
        public int $fetchedAt = 0,
        public int $lastAttemptAt = 0,
        public string $lastError = '',
        public int $lastUsedAt = 0,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string)($row['url'] ?? ''),
            (string)($row['raw_body'] ?? ''),
            (string)($row['body_hash'] ?? ''),
            (int)($row['fetched_at'] ?? 0),
            (int)($row['last_attempt_at'] ?? 0),
            (string)($row['last_error'] ?? ''),
            (int)($row['last_used_at'] ?? 0),
        );
    }

    /**
     * True once the feed has been fetched successfully at least once.
     */
    public function hasContent(): bool
    {
        return $this->rawBody !== '';
    }

    public function isFresh(int $now, int $lifetime): bool
    {
        return $this->hasContent() && $lifetime > 0 && $this->fetchedAt + $lifetime > $now;
    }
}
