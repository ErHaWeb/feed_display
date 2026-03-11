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

            $data['feed'][$field] = $value;
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
                $itemProperties[$field] = $this->getValue($item, $fieldParts);
            }

            $itemProperties = $this->eventDispatcher
                ->dispatch(new SingleFeedDataEvent($itemProperties, $item, $settings, $this->feed))
                ->getItemProperties();

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
