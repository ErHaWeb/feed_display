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

/**
 * Controller for feed display plugin
 */

namespace ErHaWeb\FeedDisplay\Controller;

use ErHaWeb\FeedDisplay\Event\SingleFeedDataEvent;
use Psr\Http\Message\ResponseInterface;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class FeedController extends ActionController
{
    public function __construct(
        private readonly FrontendInterface $cache,
        private readonly SimplePie $feed
    ) {}

    public function displayAction(): ResponseInterface
    {
        if ($this->settings) {
            $cacheIdentifier = 'feeddisplay';
            $data = $this->cache->get($cacheIdentifier);
            $cacheDuration = (int)$this->settings['cacheDuration'];

            if ($cacheDuration === 0) {
                $data = $this->getFeedData();
                $this->cache->remove($cacheIdentifier);
            } elseif ($data === false || $data['settings'] !== $this->settings) {
                $data = $this->getFeedData();
                $this->cache->set($cacheIdentifier, $data, [], $cacheDuration);
            }

            $this->view->assign('data', $data);
        }
        return $this->htmlResponse();
    }

    private function getFeedData(): array
    {
        $data = [];
        $data['settings'] = $this->settings;

        if ($this->initFeed()) {
            $getFeedFields = GeneralUtility::trimExplode(',', $this->settings['getFields']['feed']);

            foreach ($getFeedFields as $getFeedField) {
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

            $maxFeedCount = (int)($this->settings['maxFeedCount'] ?? 0);
            foreach ($this->feed->get_items(0, $maxFeedCount) as $item) {
                $getItemsFields = GeneralUtility::trimExplode(',', $this->settings['getFields']['items']);
                $itemProperties = [];

                foreach ($getItemsFields as $getItemsField) {
                    if ($getItemsField) {
                        $fieldParts = GeneralUtility::trimExplode('|', $getItemsField);
                        $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);
                        $value = $this->getValue($item, $fieldParts);
                        $itemProperties[$field] = $value;
                    }
                }

                $itemProperties = $this->eventDispatcher->dispatch(new SingleFeedDataEvent($itemProperties, $item, $this->settings, $this->feed))->getItemProperties();

                if ($itemProperties) {
                    $data['items'][] = $itemProperties;
                }
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

    private function getImage($fileUrl): ?string
    {
        if ($fileUrl) {
            $urlParts = parse_url((string)$fileUrl);
            $pathParts = pathinfo($urlParts['path']);

            $fileName = $pathParts['filename'];
            $fileExtension = $pathParts['extension'];
            $temporaryFileName = md5($fileName) . '.' . $fileExtension;
            $content = GeneralUtility::getUrl($fileUrl);

            if ($content !== false) {
                $tempFilePath = 'typo3temp/assets/images/' . $temporaryFileName;
                if (!@is_file(Environment::getPublicPath() . '/' . $tempFilePath)) {
                    GeneralUtility::writeFileToTypo3tempDir(Environment::getPublicPath() . '/' . $tempFilePath, $content);
                }
                return $tempFilePath;
            }
        }

        return null;
    }

    private function initFeed(): bool
    {
        $feedUrl = $this->settings['feedUrl'] ?? '';
        if (isset($feedUrl) && $feedUrl !== '') {
            $feedUrl = stripslashes((string)$feedUrl);
            $this->feed->set_feed_url($feedUrl);
            $this->feed->enable_cache(false);
            $this->feed->init();
            return true;
        }
        return false;
    }
}
