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
 * @phpstan-type ConfiguredFeed array{identifier: string, url: string}
 * @phpstan-type FeedItem array<string, mixed>
 * @phpstan-type FeedData array<string, mixed>
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class FeedDataService
{
    private readonly FeedValueNormalizer $valueNormalizer;
    private readonly ConfiguredFeedResolver $feedResolver;
    private readonly FeedItemPostProcessor $itemProcessor;

    public function __construct(
        private readonly SimplePie $feed,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FeedRuntimeInitializer $initializer,
        ?FeedValueNormalizer $valueNormalizer = null,
        ?ConfiguredFeedResolver $feedResolver = null,
        ?FeedItemPostProcessor $itemProcessor = null,
    ) {
        $this->valueNormalizer = $valueNormalizer ?? new FeedValueNormalizer();
        $this->feedResolver = $feedResolver ?? new ConfiguredFeedResolver();
        $this->itemProcessor = $itemProcessor ?? new FeedItemPostProcessor();
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
        $configuredFeeds = $this->feedResolver->resolve($settings);
        if ($configuredFeeds === []) {
            return $data;
        }

        $items = [];
        foreach ($configuredFeeds as $feedIndex => $configuredFeed) {
            $feed = $this->createFeed($feedIndex);
            if (!SimplePieDeprecationHandler::run(
                fn(): bool => $this->initializer->initializeFeed($feed, $settings, $configuredFeed['url'])
            )) {
                continue;
            }

            $feedData = [];
            $this->appendFeedProperties($feedData, $settings, $feed);
            if (isset($feedData['feed']) && is_array($feedData['feed'])) {
                $feedProperties = $feedData['feed'];
                $feedProperties['sourceIdentifier'] = $configuredFeed['identifier'];
                $feedProperties['sourceUrl'] = $configuredFeed['url'];
                $data['feeds'][] = $feedProperties;
                $data['feed'] ??= $feedProperties;
            }

            foreach ($this->buildItemProperties($feed, $settings, $configuredFeed) as $itemProperties) {
                $items[] = $itemProperties;
            }
        }

        $items = $this->itemProcessor->process($items, $settings);
        if ($items !== []) {
            $data['items'] = $items;
        }

        return $data;
    }

    protected function createFeed(int $sourceIndex): SimplePie
    {
        if ($sourceIndex === 0) {
            return $this->feed;
        }

        return GeneralUtility::makeInstance(SimplePie::class);
    }

    /**
     * @param FeedSettings $settings
     * @param FeedData $data
     */
    private function appendFeedProperties(array &$data, array $settings, SimplePie $feed): void
    {
        $getFeedFields = GeneralUtility::trimExplode(',', (string)($settings['getFields']['feed'] ?? ''));
        foreach ($getFeedFields as $getFeedField) {
            if ($getFeedField === '') {
                continue;
            }

            $fieldParts = GeneralUtility::trimExplode('|', $getFeedField);
            $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);
            $value = $this->getFeedFieldValue($feed, $getFeedField, $fieldParts);

            if ($getFeedField === 'image_url') {
                $data['feed']['image'] = $this->getImage($value);
            }

            $data['feed'][$field] = SimplePieDeprecationHandler::run(
                fn(): mixed => $this->valueNormalizer->normalizeValue($value)
            );
        }
    }

    /**
     * @param FeedSettings $settings
     * @param ConfiguredFeed $configuredFeed
     * @return list<FeedItem>
     */
    private function buildItemProperties(SimplePie $feed, array $settings, array $configuredFeed): array
    {
        $itemData = [];
        $maxFeedCount = (int)($settings['maxFeedCount'] ?? 0);
        $getItemsFields = GeneralUtility::trimExplode(',', (string)($settings['getFields']['items'] ?? ''));

        /** @var list<Item> $items */
        $items = SimplePieDeprecationHandler::run(
            fn(): array => $feed->get_items(0, $maxFeedCount)
        );

        foreach ($items as $item) {
            $itemProperties = $this->buildNormalizedItemProperties($item, $getItemsFields);
            $itemProperties = $this->dispatchSingleFeedDataEvent($itemProperties, $item, $settings, $feed);

            if ($itemProperties !== []) {
                $itemProperties['feedSource'] = [
                    'identifier' => $configuredFeed['identifier'],
                    'url' => $configuredFeed['url'],
                ];
                $itemData[] = $itemProperties;
            }
        }

        return $itemData;
    }

    /**
     * @param list<string> $fieldParts
     */
    private function getFeedFieldValue(SimplePie $feed, string $getFeedField, array $fieldParts): mixed
    {
        $fieldName = $fieldParts[0] ?? $getFeedField;

        if ($fieldName === 'subscribe_url') {
            return $this->callSimplePieGetter($feed->subscribe_url(...));
        }

        // Avoid calling SimplePie's deprecated favicon handling for legacy configurations.
        if ($fieldName === 'favicon') {
            return null;
        }

        return $this->getValue($feed, $fieldParts);
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
            $value = $this->getValue($item, $fieldParts);

            $itemProperties[$field] = SimplePieDeprecationHandler::run(
                fn(): mixed => $this->valueNormalizer->normalizeValue($value)
            );
        }

        return $itemProperties;
    }

    /**
     * @param FeedSettings $settings
     * @param array<string, mixed> $itemProperties
     * @return array<string, mixed>
     */
    private function dispatchSingleFeedDataEvent(array $itemProperties, Item $item, array $settings, SimplePie $feed): array
    {
        $event = $this->eventDispatcher->dispatch(
            new SingleFeedDataEvent($itemProperties, $item, $settings, $feed)
        );
        /** @var SingleFeedDataEvent $event */
        $normalizedProps = SimplePieDeprecationHandler::run(
            fn(): mixed => $this->valueNormalizer->normalizeValue($event->getItemProperties())
        );

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

        return $this->callSimplePieGetter(static fn(): mixed => match (count($fieldParts)) {
            1 => $method(),
            2 => $method($fieldParts[1]),
            3 => $method($fieldParts[1], $fieldParts[2]),
            4 => $method($fieldParts[1], $fieldParts[2], $fieldParts[3]),
            default => null,
        });
    }

    /**
     * @param callable(): mixed $getter
     */
    private function callSimplePieGetter(callable $getter): mixed
    {
        // Some getters sanitize URLs lazily and can trigger the same IRI path.
        return SimplePieDeprecationHandler::run($getter);
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
