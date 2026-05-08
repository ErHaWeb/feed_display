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

/**
 * @internal
 * @phpstan-type FeedSettings array<string, mixed>
 * @phpstan-type FeedItem array<string, mixed>
 * @phpstan-type DecoratedFeedItem array{index: int, item: FeedItem, sortValue: int|float|string|null}
 */
final class FeedItemPostProcessor
{
    /**
     * @param list<FeedItem> $items
     * @param FeedSettings $settings
     * @return list<FeedItem>
     */
    public function process(array $items, array $settings): array
    {
        $items = $this->sortItems($items, $settings);

        if ($this->isEnabled($settings['removeDuplicates'] ?? false)) {
            $items = $this->removeDuplicateItems($items);
        }

        $maxFeedCount = (int)($settings['maxFeedCount'] ?? 0);
        if ($maxFeedCount > 0) {
            $items = array_slice($items, 0, $maxFeedCount);
        }

        return $items;
    }

    /**
     * @param list<FeedItem> $items
     * @param FeedSettings $settings
     * @return list<FeedItem>
     */
    private function sortItems(array $items, array $settings): array
    {
        $sortBy = trim((string)($settings['sortBy'] ?? 'date'));
        if ($sortBy === '' || count($items) < 2) {
            return $items;
        }

        $directionMultiplier = strtolower(trim((string)($settings['sortDirection'] ?? 'desc'))) === 'asc' ? 1 : -1;
        $decoratedItems = [];
        foreach ($items as $index => $item) {
            $decoratedItems[] = [
                'index' => $index,
                'item' => $item,
                'sortValue' => $this->normalizeSortValue($item[$sortBy] ?? null),
            ];
        }

        usort(
            $decoratedItems,
            fn(array $left, array $right): int => $this->compareDecoratedItems($left, $right, $directionMultiplier)
        );

        return array_column($decoratedItems, 'item');
    }

    /**
     * @param DecoratedFeedItem $left
     * @param DecoratedFeedItem $right
     */
    private function compareDecoratedItems(array $left, array $right, int $directionMultiplier): int
    {
        if ($left['sortValue'] === null || $right['sortValue'] === null) {
            return $this->compareNullableSortValues($left, $right);
        }

        $comparison = $left['sortValue'] <=> $right['sortValue'];
        if ($comparison === 0) {
            return $left['index'] <=> $right['index'];
        }

        return $comparison * $directionMultiplier;
    }

    /**
     * @param DecoratedFeedItem $left
     * @param DecoratedFeedItem $right
     */
    private function compareNullableSortValues(array $left, array $right): int
    {
        if ($left['sortValue'] === null && $right['sortValue'] !== null) {
            return 1;
        }
        if ($left['sortValue'] !== null && $right['sortValue'] === null) {
            return -1;
        }

        return $left['index'] <=> $right['index'];
    }

    private function normalizeSortValue(mixed $value): int|float|string|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return $timestamp;
        }

        return strtolower($value);
    }

    /**
     * @param list<FeedItem> $items
     * @return list<FeedItem>
     */
    private function removeDuplicateItems(array $items): array
    {
        $deduplicatedItems = [];
        $knownIds = [];

        foreach ($items as $item) {
            $duplicateIdentifier = $this->getDuplicateIdentifier($item);
            if ($duplicateIdentifier !== null && isset($knownIds[$duplicateIdentifier])) {
                continue;
            }

            if ($duplicateIdentifier !== null) {
                $knownIds[$duplicateIdentifier] = true;
            }
            $deduplicatedItems[] = $item;
        }

        return $deduplicatedItems;
    }

    /**
     * @param FeedItem $item
     */
    private function getDuplicateIdentifier(array $item): ?string
    {
        foreach (['id', 'permalink', 'link'] as $fieldName) {
            if (!isset($item[$fieldName]) || !is_scalar($item[$fieldName])) {
                continue;
            }

            $fieldValue = trim((string)$item[$fieldName]);
            if ($fieldValue !== '') {
                return $fieldName . ':' . $fieldValue;
            }
        }

        if (isset($item['title'], $item['date']) && is_scalar($item['title']) && is_scalar($item['date'])) {
            return 'title-date:' . trim((string)$item['title']) . '|' . trim((string)$item['date']);
        }

        return null;
    }

    private function isEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
