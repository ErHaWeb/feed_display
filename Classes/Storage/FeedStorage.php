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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/** @internal Persists source documents independently of TYPO3 caches. */
final class FeedStorage
{
    public const TABLE = 'tx_feeddisplay_feed';

    public function __construct(private readonly ConnectionPool $connectionPool, private readonly LoggerInterface $logger) {}

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    public static function cacheTag(string $url): string
    {
        return 'feeddisplay_url_' . self::hashUrl($url);
    }

    public static function revisionTag(string $url, string $revision): string
    {
        return 'feeddisplay_rev_' . self::hashUrl($url) . '_' . $revision;
    }

    public function findRevisionByHash(string $urlHash): ?string
    {
        $revision = $this->connection()->select(['revision'], self::TABLE, ['url_hash' => $urlHash])->fetchOne();
        return $revision === false ? null : (string)$revision;
    }

    public function find(string $url): ?StoredFeed
    {
        $row = $this->connection()->select(['*'], self::TABLE, ['url_hash' => self::hashUrl($url)])->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }
        $document = (string)$row['revision'] === '' ? null : $this->decodeDocument($url, (string)$row['revision'], $row['response_data']);
        return $this->stateFromRow($row, $document);
    }

    public function findState(string $url): ?StoredFeed
    {
        $row = $this->connection()->select(
            ['url', 'revision', 'fetched_at', 'last_attempt_at', 'last_error', 'invalidation_pending'],
            self::TABLE,
            ['url_hash' => self::hashUrl($url)],
        )->fetchAssociative();
        return is_array($row) ? $this->stateFromRow($row) : null;
    }

    public function loadDocument(string $url, string $revision): ?FeedDocument
    {
        $row = $this->connection()->select(['response_data'], self::TABLE, [
            'url_hash' => self::hashUrl($url), 'revision' => $revision,
        ])->fetchAssociative();
        // A different revision is a concurrent replacement, not corrupt data.
        return is_array($row) ? $this->decodeDocument($url, $revision, $row['response_data']) : null;
    }

    private function decodeDocument(string $url, string $revision, mixed $bytes): ?FeedDocument
    {
        try {
            if (is_resource($bytes)) {
                $bytes = stream_get_contents($bytes);
            }
            if (!is_string($bytes) || !hash_equals($revision, hash('sha256', $bytes))) {
                throw new \UnexpectedValueException('Stored feed checksum mismatch.');
            }
            return FeedDocument::decode($bytes);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not decode the stored feed.', ['url' => $url, 'exception' => $exception]);
            return null;
        }
    }

    /** @param array<string, mixed> $row */
    private function stateFromRow(array $row, ?FeedDocument $document = null): StoredFeed
    {
        return new StoredFeed(
            (string)$row['url'],
            $document,
            (string)$row['revision'],
            (int)$row['fetched_at'],
            (int)$row['last_attempt_at'],
            (string)$row['last_error'],
            (bool)$row['invalidation_pending'],
        );
    }

    public function storeSuccess(string $url, FeedDocument $document, int $now): bool
    {
        $previous = $this->find($url);
        $revision = $document->revision();
        $changed = $previous === null || $previous->document === null || $previous->revision !== $revision;
        $fields = ['fetched_at' => $now, 'last_attempt_at' => $now, 'last_error' => ''];
        if ($changed) {
            $fields += ['response_data' => $document->encode(), 'revision' => $revision, 'invalidation_pending' => 1];
        }
        $this->write($url, $fields, $previous !== null);
        return $changed;
    }

    public function storeFailure(string $url, string $error, int $now): void
    {
        $this->write($url, [
            'last_attempt_at' => $now,
            'last_error' => mb_convert_encoding($error, 'UTF-8', 'UTF-8'),
        ], $this->findState($url) !== null);
    }

    public function markInvalidated(string $url, string $revision): void
    {
        // Never clear a pending invalidation belonging to a concurrently newer revision.
        $this->connection()->update(self::TABLE, ['invalidation_pending' => 0], [
            'url_hash' => self::hashUrl($url), 'revision' => $revision,
        ]);
    }

    /** @return list<string> */
    public function findUrls(): array
    {
        return array_map(strval(...), $this->connection()->select(['url'], self::TABLE, [], [], ['uid' => 'ASC'])->fetchFirstColumn());
    }

    public function remove(string $url): void
    {
        $this->connection()->delete(self::TABLE, ['url_hash' => self::hashUrl($url)]);
    }

    /** @param array<string, int|string> $fields */
    private function write(string $url, array $fields, bool $exists): void
    {
        $connection = $this->connection();
        $identifier = ['url_hash' => self::hashUrl($url)];
        $types = isset($fields['response_data']) ? ['response_data' => Connection::PARAM_LOB] : [];
        if ($exists) {
            // All successful-response fields change in one statement; no delete/insert gap.
            $connection->update(self::TABLE, $fields, $identifier, $types);
            return;
        }
        try {
            $connection->insert(self::TABLE, $identifier + ['url' => $url] + $fields, $types);
        } catch (UniqueConstraintViolationException) {
            $connection->update(self::TABLE, $fields, $identifier, $types);
        }
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
