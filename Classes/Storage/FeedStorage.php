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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists the last successful response of each feed URL, so it can be
 * rendered while the feed itself is unavailable.
 *
 * @internal
 */
class FeedStorage
{
    public const TABLE = 'tx_feeddisplay_feed';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * Tag of all cache entries rendered from this feed.
     */
    public static function getPageCacheTag(string $url): string
    {
        return 'feeddisplay_' . self::hashUrl($url);
    }

    public function find(string $url): ?StoredFeed
    {
        $row = $this->getConnection()->select(['*'], self::TABLE, ['url_hash' => self::hashUrl($url)])->fetchAssociative();

        return is_array($row) ? StoredFeed::fromRow($row) : null;
    }

    /**
     * Stores a successfully fetched response.
     *
     * @return bool True if the response differs from the previously stored one
     */
    public function storeSuccess(string $url, string $rawBody, int $now): bool
    {
        $bodyHash = hash('sha256', $rawBody);
        $previousHash = $this->find($url)?->bodyHash;

        $this->upsert($url, [
            'raw_body' => $rawBody,
            'body_hash' => $bodyHash,
            'fetched_at' => $now,
            'last_attempt_at' => $now,
            'last_error' => '',
        ]);

        return $previousHash !== $bodyHash;
    }

    /**
     * Records a failed fetch. The last successful response is kept.
     */
    public function storeFailure(string $url, string $error, int $now): void
    {
        $this->upsert($url, [
            'last_attempt_at' => $now,
            'last_error' => $error,
        ]);
    }

    public function markUsed(string $url, int $now): void
    {
        $this->upsert($url, ['last_used_at' => $now]);
    }

    /**
     * @return list<string>
     */
    public function findUrlsUsedSince(int $timestamp): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $urls = $queryBuilder
            ->select('url')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->gte(
                'last_used_at',
                $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)
            ))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(strval(...), $urls);
    }

    /**
     * @param list<string> $keepUrls Feeds to keep regardless of their last use
     * @return int Number of deleted feeds
     */
    public function deleteUnusedSince(int $timestamp, array $keepUrls = []): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt(
                'last_used_at',
                $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)
            ));

        if ($keepUrls !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->notIn(
                'url_hash',
                $queryBuilder->createNamedParameter(array_map(self::hashUrl(...), $keepUrls), Connection::PARAM_STR_ARRAY)
            ));
        }

        return $queryBuilder->executeStatement();
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function upsert(string $url, array $fields): void
    {
        $connection = $this->getConnection();
        $identifier = ['url_hash' => self::hashUrl($url)];

        if ($connection->update(self::TABLE, $fields, $identifier) > 0) {
            return;
        }

        try {
            $connection->insert(self::TABLE, $identifier + ['url' => $url] + $fields);
        } catch (UniqueConstraintViolationException) {
            // Inserted concurrently, or the update matched a row without changing it.
            $connection->update(self::TABLE, $fields, $identifier);
        }
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
