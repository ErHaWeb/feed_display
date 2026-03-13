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

use ErHaWeb\FeedDisplay\Event\SingleFeedDataEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use SimplePie\Author;
use SimplePie\Category;
use SimplePie\Enclosure;
use SimplePie\Item;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @internal
 */
class FeedDataService
{
    public function __construct(
        private readonly SimplePie $feed,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly GuzzleClientFactory $guzzleClientFactory,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly UriFactoryInterface $uriFactory,
    ) {}

    public function buildData(array $settings): array
    {
        $data = [
            'settings' => $settings,
        ];

        if (!$this->initializeFeed($settings)) {
            return $data;
        }

        $getFeedFields = GeneralUtility::trimExplode(',', (string)($settings['getFields']['feed'] ?? ''));
        foreach ($getFeedFields as $getFeedField) {
            if ($getFeedField === '') {
                continue;
            }
            $fieldParts = GeneralUtility::trimExplode('|', $getFeedField);
            $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);

            if ($getFeedField === 'subscribe_url') {
                $value = $this->feed->subscribe_url();
            } else {
                $value = $this->getValue($this->feed, $fieldParts);
            }

            if ($getFeedField === 'image_url') {
                $data['feed']['image'] = $this->getImage($value);
            }

            $data['feed'][$field] = $this->normalizeValue($value);
        }

        $maxFeedCount = (int)($settings['maxFeedCount'] ?? 0);
        $getItemsFields = GeneralUtility::trimExplode(',', (string)($settings['getFields']['items'] ?? ''));
        foreach ($this->feed->get_items(0, $maxFeedCount) as $item) {
            $itemProperties = [];

            foreach ($getItemsFields as $getItemsField) {
                if ($getItemsField === '') {
                    continue;
                }
                $fieldParts = GeneralUtility::trimExplode('|', $getItemsField);
                $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);
                $itemProperties[$field] = $this->normalizeValue($this->getValue($item, $fieldParts));
            }

            $itemProperties = $this->eventDispatcher
                ->dispatch(new SingleFeedDataEvent($itemProperties, $item, $settings, $this->feed))
                ->getItemProperties();
            $itemProperties = $this->normalizeValue($itemProperties);

            if ($itemProperties !== []) {
                $data['items'][] = $itemProperties;
            }
        }

        return $data;
    }

    private function getValue(object $object, array $fieldParts): mixed
    {
        $getMethod = 'get_' . $fieldParts[0];
        $value = null;

        if (method_exists($object, $getMethod)) {
            switch (count($fieldParts)) {
                case 1:
                    $value = $object->$getMethod();
                    break;
                case 2:
                    $value = $object->$getMethod($fieldParts[1]);
                    break;
                case 3:
                    $value = $object->$getMethod($fieldParts[1], $fieldParts[2]);
                    break;
                case 4:
                    $value = $object->$getMethod($fieldParts[1], $fieldParts[2], $fieldParts[3]);
                    break;
            }
        }

        return $value;
    }

    private function normalizeValue(mixed $value, ?\SplObjectStorage $processedObjects = null, int $depth = 0): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($depth >= 8) {
            return null;
        }

        $processedObjects ??= new \SplObjectStorage();

        if (is_array($value)) {
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

        if (!is_object($value)) {
            return null;
        }

        if ($processedObjects->contains($value)) {
            return null;
        }

        $processedObjects->attach($value);

        try {
            if ($value instanceof Author) {
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

            if ($value instanceof Category) {
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

            if ($value instanceof Enclosure) {
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

            if ($value instanceof Item) {
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

            if ($value instanceof SimplePie) {
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
                            'favicon' => $value->get_favicon(...),
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

            if ($value instanceof \Stringable) {
                return (string)$value;
            }

            $publicProperties = get_object_vars($value);
            if ($publicProperties !== []) {
                return $this->normalizeValue($publicProperties, $processedObjects, $depth + 1);
            }

            return null;
        } finally {
            $processedObjects->detach($value);
        }
    }

    /**
     * @param array<string, callable(): mixed> $methods
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

    private function getImage(mixed $fileUrl): ?string
    {
        if ($fileUrl) {
            $urlParts = parse_url((string)$fileUrl);
            $pathParts = pathinfo($urlParts['path'] ?? '');

            if (($pathParts['filename'] ?? '') === '' || ($pathParts['extension'] ?? '') === '') {
                return null;
            }

            $temporaryFileName = md5((string)$pathParts['filename']) . '.' . $pathParts['extension'];
            $content = GeneralUtility::getUrl((string)$fileUrl);

            if ($content !== false) {
                $tempFilePath = 'typo3temp/assets/images/' . $temporaryFileName;
                $absoluteTempFilePath = Environment::getPublicPath() . '/' . $tempFilePath;
                if (!@is_file($absoluteTempFilePath)) {
                    GeneralUtility::writeFileToTypo3tempDir($absoluteTempFilePath, $content);
                }
                return $tempFilePath;
            }
        }

        return null;
    }

    private function initializeFeed(array $settings): bool
    {
        $feedUrl = $settings['feedUrl'] ?? '';
        if ($feedUrl === '') {
            return false;
        }

        $feedUrl = stripslashes((string)$feedUrl);
        $this->feed->set_feed_url($feedUrl);
        if ($this->shouldUseTypo3HttpClient($feedUrl)) {
            $this->feed->set_http_client(
                $this->guzzleClientFactory->getClient(),
                $this->requestFactory,
                $this->uriFactory
            );
        }
        $this->feed->enable_cache(false);
        $this->feed->init();
        return true;
    }

    private function shouldUseTypo3HttpClient(string $feedUrl): bool
    {
        $scheme = parse_url($feedUrl, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
