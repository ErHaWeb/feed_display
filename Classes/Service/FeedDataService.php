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
use SimplePie\Item;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @internal
 * @phpstan-type FeedSettings array<string, mixed>
 * @phpstan-type FeedItem array<string, mixed>
 * @phpstan-type FeedData array<string, mixed>
 */
class FeedDataService
{
    private readonly FeedValueNormalizer $valueNormalizer;

    public function __construct(
        private readonly SimplePie $feed,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FeedRuntimeInitializer $initializer,
        ?FeedValueNormalizer $valueNormalizer = null,
    ) {
        $this->valueNormalizer = $valueNormalizer ?? new FeedValueNormalizer();
    }

    /**
     * @param FeedSettings $settings
     * @return FeedData
     */
    public function buildData(array $settings): array
    {
        $data = [
            'settings' => $settings,
        ];

        if (!$this->initializer->initializeFeed($this->feed, $settings)) {
            return $data;
        }

        $this->appendFeedProperties($data, $settings);
        $this->appendItemProperties($data, $settings);

        return $data;
    }

    /**
     * @param FeedSettings $settings
     * @param FeedData $data
     */
    private function appendFeedProperties(array &$data, array $settings): void
    {
        $getFeedFields = GeneralUtility::trimExplode(',', (string)($settings['getFields']['feed'] ?? ''));
        foreach ($getFeedFields as $getFeedField) {
            if ($getFeedField === '') {
                continue;
            }

            $fieldParts = GeneralUtility::trimExplode('|', $getFeedField);
            $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);
            $value = $this->getFeedFieldValue($getFeedField, $fieldParts);

            if ($getFeedField === 'image_url') {
                $data['feed']['image'] = $this->getImage($value);
            }

            $data['feed'][$field] = $this->valueNormalizer->normalizeValue($value);
        }
    }

    /**
     * @param FeedSettings $settings
     * @param FeedData $data
     */
    private function appendItemProperties(array &$data, array $settings): void
    {
        $maxFeedCount = (int)($settings['maxFeedCount'] ?? 0);
        $getItemsFields = GeneralUtility::trimExplode(',', (string)($settings['getFields']['items'] ?? ''));

        foreach ($this->feed->get_items(0, $maxFeedCount) as $item) {
            $itemProperties = $this->buildNormalizedItemProperties($item, $getItemsFields);
            $itemProperties = $this->dispatchSingleFeedDataEvent($itemProperties, $item, $settings);

            if ($itemProperties !== []) {
                $data['items'][] = $itemProperties;
            }
        }
    }

    /**
     * @param list<string> $fieldParts
     */
    private function getFeedFieldValue(string $getFeedField, array $fieldParts): mixed
    {
        $fieldName = $fieldParts[0] ?? $getFeedField;

        if ($fieldName === 'subscribe_url') {
            return $this->feed->subscribe_url();
        }

        // Avoid calling SimplePie's deprecated favicon handling for legacy configurations.
        if ($fieldName === 'favicon') {
            return null;
        }

        return $this->getValue($this->feed, $fieldParts);
    }

    /**
     * @param list<string> $getItemsFields
     * @return array<string, mixed>
     */
    private function buildNormalizedItemProperties(Item $item, array $getItemsFields): array
    {
        $itemProperties = [];

        foreach ($getItemsFields as $getItemsField) {
            if ($getItemsField === '') {
                continue;
            }

            $fieldParts = GeneralUtility::trimExplode('|', $getItemsField);
            $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);
            $itemProperties[$field] = $this->valueNormalizer->normalizeValue($this->getValue($item, $fieldParts));
        }

        return $itemProperties;
    }

    /**
     * @param FeedSettings $settings
     * @param array<string, mixed> $itemProperties
     * @return array<string, mixed>
     */
    private function dispatchSingleFeedDataEvent(array $itemProperties, Item $item, array $settings): array
    {
        $event = $this->eventDispatcher->dispatch(
            new SingleFeedDataEvent($itemProperties, $item, $settings, $this->feed)
        );
        /** @var SingleFeedDataEvent $event */
        $normalizedProps = $this->valueNormalizer->normalizeValue($event->getItemProperties());

        return is_array($normalizedProps) ? $normalizedProps : [];
    }

    /**
     * @param list<string> $fieldParts
     */
    private function getValue(object $object, array $fieldParts): mixed
    {
        $getMethod = 'get_' . $fieldParts[0];
        $callable = [$object, $getMethod];

        if (!is_callable($callable)) {
            return null;
        }

        $method = \Closure::fromCallable($callable);

        return match (count($fieldParts)) {
            1 => $method(),
            2 => $method($fieldParts[1]),
            3 => $method($fieldParts[1], $fieldParts[2]),
            4 => $method($fieldParts[1], $fieldParts[2], $fieldParts[3]),
            default => null,
        };
    }

    private function getImage(mixed $fileUrl): ?string
    {
        if (!is_string($fileUrl) || $fileUrl === '') {
            return null;
        }

        $urlParts = parse_url($fileUrl);
        $pathParts = pathinfo($urlParts['path'] ?? '');
        $filename = $pathParts['filename'];
        $extension = $pathParts['extension'] ?? '';

        if ($filename === '' || $extension === '') {
            return null;
        }

        $temporaryFileName = md5($filename) . '.' . $extension;
        $content = GeneralUtility::getUrl($fileUrl);
        if ($content === false) {
            return null;
        }

        $tempFilePath = 'typo3temp/assets/images/' . $temporaryFileName;
        $absoluteTempFilePath = Environment::getPublicPath() . '/' . $tempFilePath;
        if (!@is_file($absoluteTempFilePath)) {
            GeneralUtility::writeFileToTypo3tempDir($absoluteTempFilePath, $content);
        }

        return $tempFilePath;
    }

}
