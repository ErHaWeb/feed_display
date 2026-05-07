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

use SimplePie\Author;
use SimplePie\Category;
use SimplePie\Enclosure;
use SimplePie\Item;
use SimplePie\SimplePie;

/**
 * @internal
 */
final class FeedValueNormalizer
{
    /**
     * @param \SplObjectStorage<object, bool>|null $processedObjects
     */
    public function normalizeValue(mixed $value, ?\SplObjectStorage $processedObjects = null, int $depth = 0): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($depth >= 8) {
            return null;
        }

        $processedObjects ??= new \SplObjectStorage();

        if (is_array($value)) {
            return $this->normalizeArray($value, $processedObjects, $depth);
        }

        if (!is_object($value)) {
            return null;
        }

        return $this->normalizeObject($value, $processedObjects, $depth);
    }

    /**
     * @param array<mixed> $value
     * @param \SplObjectStorage<object, bool> $processedObjects
     * @return array<mixed>
     */
    private function normalizeArray(array $value, \SplObjectStorage $processedObjects, int $depth): array
    {
        if (array_is_list($value)) {
            $normalizedList = [];
            foreach ($value as $listValue) {
                $normalizedValue = $this->normalizeValue($listValue, $processedObjects, $depth + 1);
                if ($normalizedValue !== null && $normalizedValue !== []) {
                    $normalizedList[] = $normalizedValue;
                }
            }

            return $normalizedList;
        }

        foreach ($value as $key => $arrayValue) {
            $value[$key] = $this->normalizeValue($arrayValue, $processedObjects, $depth + 1);
        }

        return $value;
    }

    /**
     * @param \SplObjectStorage<object, bool> $processedObjects
     */
    private function normalizeObject(object $value, \SplObjectStorage $processedObjects, int $depth): mixed
    {
        if ($processedObjects->offsetExists($value)) {
            return null;
        }

        $processedObjects->offsetSet($value, true);

        try {
            return match (true) {
                $value instanceof Author => $this->normalizeAuthor($value, $processedObjects, $depth),
                $value instanceof Category => $this->normalizeCategory($value, $processedObjects, $depth),
                $value instanceof Enclosure => $this->normalizeEnclosure($value, $processedObjects, $depth),
                $value instanceof Item => $this->normalizeItem($value, $processedObjects, $depth),
                $value instanceof SimplePie => $this->normalizeFeed($value, $processedObjects, $depth),
                $value instanceof \Stringable => (string)$value,
                default => $this->normalizePublicProperties($value, $processedObjects, $depth),
            };
        } finally {
            $processedObjects->offsetUnset($value);
        }
    }

    /**
     * @param \SplObjectStorage<object, bool> $processedObjects
     * @return array<string, mixed>
     */
    private function normalizeAuthor(Author $value, \SplObjectStorage $processedObjects, int $depth): array
    {
        return $this->filterNormalizedValues(
            $this->normalizeMethodMap(
                [
                    'email' => $value->get_email(...),
                    'link' => $value->get_link(...),
                    'name' => $value->get_name(...),
                ],
                $processedObjects,
                $depth
            )
        );
    }

    /**
     * @param \SplObjectStorage<object, bool> $processedObjects
     * @return array<string, mixed>
     */
    private function normalizeCategory(Category $value, \SplObjectStorage $processedObjects, int $depth): array
    {
        return $this->filterNormalizedValues(
            $this->normalizeMethodMap(
                [
                    'label' => $value->get_label(...),
                    'scheme' => $value->get_scheme(...),
                    'term' => $value->get_term(...),
                ],
                $processedObjects,
                $depth
            )
        );
    }

    /**
     * @param \SplObjectStorage<object, bool> $processedObjects
     * @return array<string, mixed>
     */
    private function normalizeEnclosure(Enclosure $value, \SplObjectStorage $processedObjects, int $depth): array
    {
        return $this->filterNormalizedValues(
            $this->normalizeMethodMap(
                [
                    'caption' => $value->get_caption(...),
                    'description' => $value->get_description(...),
                    'duration' => $value->get_duration(...),
                    'expression' => $value->get_expression(...),
                    'height' => $value->get_height(...),
                    'keywords' => $value->get_keywords(...),
                    'language' => $value->get_language(...),
                    'length' => $value->get_length(...),
                    'link' => $value->get_link(...),
                    'medium' => $value->get_medium(...),
                    'rating' => $value->get_rating(...),
                    'thumbnails' => $value->get_thumbnails(...),
                    'title' => $value->get_title(...),
                    'type' => $value->get_type(...),
                    'width' => $value->get_width(...),
                ],
                $processedObjects,
                $depth
            )
        );
    }

    /**
     * @param \SplObjectStorage<object, bool> $processedObjects
     * @return array<string, mixed>
     */
    private function normalizeItem(Item $value, \SplObjectStorage $processedObjects, int $depth): array
    {
        return $this->filterNormalizedValues(
            $this->normalizeMethodMap(
                [
                    'author' => $value->get_author(...),
                    'authors' => $value->get_authors(...),
                    'base' => $value->get_base(...),
                    'category' => $value->get_category(...),
                    'categories' => $value->get_categories(...),
                    'content' => $value->get_content(...),
                    'contributor' => $value->get_contributor(...),
                    'contributors' => $value->get_contributors(...),
                    'copyright' => $value->get_copyright(...),
                    'description' => $value->get_description(...),
                    'enclosure' => $value->get_enclosure(...),
                    'enclosures' => $value->get_enclosures(...),
                    'id' => static fn(): mixed => $value->get_id(),
                    'latitude' => $value->get_latitude(...),
                    'link' => static fn(): mixed => $value->get_link(),
                    'links' => $value->get_links(...),
                    'longitude' => $value->get_longitude(...),
                    'permalink' => $value->get_permalink(...),
                    'source' => $value->get_source(...),
                    'title' => $value->get_title(...),
                ],
                $processedObjects,
                $depth
            )
        );
    }

    /**
     * @param \SplObjectStorage<object, bool> $processedObjects
     * @return array<string, mixed>
     */
    private function normalizeFeed(SimplePie $value, \SplObjectStorage $processedObjects, int $depth): array
    {
        return $this->filterNormalizedValues(
            $this->normalizeMethodMap(
                [
                    'allDiscoveredFeeds' => $value->get_all_discovered_feeds(...),
                    'author' => $value->get_author(...),
                    'authors' => $value->get_authors(...),
                    'base' => $value->get_base(...),
                    'contributor' => $value->get_contributor(...),
                    'contributors' => $value->get_contributors(...),
                    'copyright' => $value->get_copyright(...),
                    'description' => $value->get_description(...),
                    'encoding' => $value->get_encoding(...),
                    'imageHeight' => $value->get_image_height(...),
                    'imageLink' => $value->get_image_link(...),
                    'imageTitle' => $value->get_image_title(...),
                    'imageUrl' => $value->get_image_url(...),
                    'imageWidth' => $value->get_image_width(...),
                    'itemQuantity' => $value->get_item_quantity(...),
                    'items' => static fn(): mixed => $value->get_items(),
                    'language' => $value->get_language(...),
                    'latitude' => $value->get_latitude(...),
                    'link' => static fn(): mixed => $value->get_link(),
                    'links' => $value->get_links(...),
                    'longitude' => $value->get_longitude(...),
                    'permalink' => $value->get_permalink(...),
                    'subscribeUrl' => $value->subscribe_url(...),
                    'title' => $value->get_title(...),
                    'type' => $value->get_type(...),
                ],
                $processedObjects,
                $depth
            )
        );
    }

    /**
     * @param \SplObjectStorage<object, bool> $processedObjects
     */
    private function normalizePublicProperties(object $value, \SplObjectStorage $processedObjects, int $depth): mixed
    {
        $publicProperties = get_object_vars($value);
        if ($publicProperties === []) {
            return null;
        }

        return $this->normalizeValue($publicProperties, $processedObjects, $depth + 1);
    }

    /**
     * @param array<string, callable(): mixed> $methods
     * @param \SplObjectStorage<object, bool> $processedObjects
     * @return array<string, mixed>
     */
    private function normalizeMethodMap(array $methods, \SplObjectStorage $processedObjects, int $depth): array
    {
        $normalizedValues = [];
        foreach ($methods as $key => $method) {
            try {
                $normalizedValues[$key] = $this->normalizeValue($method(), $processedObjects, $depth + 1);
            } catch (\Throwable) {
                $normalizedValues[$key] = null;
            }
        }

        return $normalizedValues;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function filterNormalizedValues(array $values): array
    {
        return array_filter(
            $values,
            static fn(mixed $normalizedValue): bool => $normalizedValue !== null && $normalizedValue !== ''
        );
    }
}
