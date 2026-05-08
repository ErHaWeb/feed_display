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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @internal
 * @phpstan-type FeedSettings array<string, mixed>
 * @phpstan-type ConfiguredFeed array{identifier: string, url: string}
 */
final class ConfiguredFeedResolver
{
    private const FEED_TABLE = 'tx_feeddisplay_domain_model_feed';

    public function __construct(
        private readonly ?ConnectionPool $connectionPool = null,
    ) {}

    /**
     * @param FeedSettings $settings
     * @return list<ConfiguredFeed>
     */
    public function resolve(array $settings): array
    {
        $configuredFeeds = $this->resolveConfiguredFeedsFromValue($settings['feeds'] ?? null);
        if ($configuredFeeds !== []) {
            return $configuredFeeds;
        }

        $legacyFeedUrl = $this->normalizeFeedUrl($settings['feedUrl'] ?? '');
        if ($legacyFeedUrl === '') {
            return [];
        }

        return [[
            'identifier' => 'legacy',
            'url' => $legacyFeedUrl,
        ]];
    }

    /**
     * @return list<ConfiguredFeed>
     */
    private function resolveConfiguredFeedsFromValue(mixed $feeds): array
    {
        if (is_scalar($feeds)) {
            return $this->resolveConfiguredFeedsFromScalar($feeds);
        }

        if (is_array($feeds)) {
            return $this->resolveConfiguredFeedsFromArray($feeds);
        }

        return [];
    }

    /**
     * @return list<ConfiguredFeed>
     */
    private function resolveConfiguredFeedsFromScalar(mixed $feeds): array
    {
        $configuredFeeds = $this->resolveConfiguredFeedsFromStringList((string)$feeds);
        if ($configuredFeeds !== []) {
            return $configuredFeeds;
        }

        return $this->resolveConfiguredFeedsFromInlineReferences($feeds);
    }

    /**
     * @param array<string|int, mixed> $feeds
     * @return list<ConfiguredFeed>
     */
    private function resolveConfiguredFeedsFromArray(array $feeds): array
    {
        $configuredFeeds = [];
        if (isset($feeds['_typoScriptNodeValue']) && is_scalar($feeds['_typoScriptNodeValue'])) {
            $configuredFeeds = $this->resolveConfiguredFeedsFromStringList((string)$feeds['_typoScriptNodeValue']);
        }

        foreach ($feeds as $identifier => $configuredFeed) {
            if ($identifier === '_typoScriptNodeValue') {
                continue;
            }
            $feedUrl = $this->extractFeedUrl($configuredFeed);
            if ($feedUrl === '') {
                continue;
            }

            $configuredFeeds[] = [
                'identifier' => (string)$identifier,
                'url' => $feedUrl,
            ];
        }

        if ($configuredFeeds !== []) {
            return $configuredFeeds;
        }

        return $this->resolveConfiguredFeedsFromInlineReferences($feeds);
    }

    /**
     * @return list<ConfiguredFeed>
     */
    private function resolveConfiguredFeedsFromStringList(string $feedList): array
    {
        $configuredFeeds = [];
        $feedUrls = GeneralUtility::trimExplode(
            ',',
            str_replace(["\r\n", "\r", "\n"], ',', $feedList),
            true
        );

        foreach ($feedUrls as $index => $feedUrl) {
            $feedUrl = $this->normalizeFeedUrl($feedUrl);
            if ($feedUrl === '' || ctype_digit($feedUrl)) {
                continue;
            }

            $configuredFeeds[] = [
                'identifier' => (string)(($index + 1) * 10),
                'url' => $feedUrl,
            ];
        }

        return $configuredFeeds;
    }

    /**
     * @return list<ConfiguredFeed>
     */
    private function resolveConfiguredFeedsFromInlineReferences(mixed $feedReferences): array
    {
        if ($this->connectionPool === null) {
            return [];
        }

        $feedUids = $this->extractFeedUids($feedReferences);
        if ($feedUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FEED_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid', 'url')
            ->from(self::FEED_TABLE)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($feedUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            );

        $feedUrlsByUid = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $feedRecord) {
            $feedUrl = $this->normalizeFeedUrl($feedRecord['url'] ?? '');
            if ($feedUrl === '') {
                continue;
            }
            $feedUrlsByUid[(int)$feedRecord['uid']] = $feedUrl;
        }

        $configuredFeeds = [];
        foreach ($feedUids as $feedUid) {
            if (!isset($feedUrlsByUid[$feedUid])) {
                continue;
            }
            $configuredFeeds[] = [
                'identifier' => (string)$feedUid,
                'url' => $feedUrlsByUid[$feedUid],
            ];
        }

        return $configuredFeeds;
    }

    /**
     * @return list<int>
     */
    private function extractFeedUids(mixed $feedReferences): array
    {
        if (is_scalar($feedReferences)) {
            $feedUids = [];
            foreach (GeneralUtility::intExplode(',', (string)$feedReferences, true) as $feedUid) {
                if ($feedUid > 0) {
                    $feedUids[] = $feedUid;
                }
            }

            return array_values(array_unique($feedUids));
        }

        if (!is_array($feedReferences)) {
            return [];
        }

        $feedUids = [];
        foreach ($feedReferences as $feedReference) {
            foreach ($this->extractFeedUids($feedReference) as $feedUid) {
                $feedUids[] = $feedUid;
            }
        }

        return array_values(array_unique($feedUids));
    }

    private function extractFeedUrl(mixed $configuredFeed): string
    {
        if (is_scalar($configuredFeed)) {
            $configuredFeed = (string)$configuredFeed;
            if (str_contains($configuredFeed, ',') || str_contains($configuredFeed, "\n") || ctype_digit(trim($configuredFeed))) {
                return '';
            }

            return $this->normalizeFeedUrl($configuredFeed);
        }

        if (!is_array($configuredFeed)) {
            return '';
        }

        if (array_key_exists('url', $configuredFeed)) {
            return $this->normalizeFeedUrl($configuredFeed['url']);
        }

        foreach ($configuredFeed as $nestedConfiguredFeed) {
            $feedUrl = $this->extractFeedUrl($nestedConfiguredFeed);
            if ($feedUrl !== '') {
                return $feedUrl;
            }
        }

        return '';
    }

    private function normalizeFeedUrl(mixed $feedUrl): string
    {
        $feedUrl = trim(stripslashes((string)$feedUrl));

        return $feedUrl === '0' ? '' : $feedUrl;
    }
}
