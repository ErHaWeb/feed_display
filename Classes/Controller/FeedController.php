<?php

/*
 * This file is part of the TYPO3 CMS project.
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

use Psr\Http\Message\ResponseInterface;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class FeedController extends ActionController
{
    /**
     * @var FrontendInterface
     */
    private FrontendInterface $cache;

    /**
     * @var SimplePie
     */
    private SimplePie $feed;

    public function __construct(FrontendInterface $cache, SimplePie $feed)
    {
        $this->cache = $cache;
        $this->feed = $feed;
    }

    /**
     * @return ResponseInterface
     */
    public function displayAction(): ResponseInterface
    {
        $cacheIdentifier = "feeddisplay";
        $cacheDuration = (int)$this->settings['cacheDuration'];

        if ($cacheDuration) {
            if (($data = $this->cache->get($cacheIdentifier)) === false) {
                $data = $this->getFeedData();
                $lifetime = (int)$this->settings['cacheDuration'];
                $this->cache->set($cacheIdentifier, $data, [], $lifetime);
            }
        } else {
            $data = $this->getFeedData();
        }

        $assignedValues = [
            'data' => $data
        ];

        $this->view->assignMultiple($assignedValues);

        return $this->htmlResponse();
    }

    /**
     * @return array
     */
    private function getFeedData(): array
    {
        $this->initFeed();
        $data = [];
        $data['feed'] = [
            'encoding' => $this->feed->get_encoding(),
            'type' => $this->feed->get_type(),
            'subscribeUrl' => $this->feed->subscribe_url(),
            'base' => $this->feed->get_base(),
            'title' => $this->feed->get_title(),
            'categories' => $this->feed->get_categories(),
            'authors' => $this->feed->get_authors(),
            'contributors' => $this->feed->get_contributors(),
            'links' => $this->feed->get_links(),
            'description' => $this->feed->get_description(),
            'copyright' => $this->feed->get_copyright(),
            'language' => $this->feed->get_language(),
            'latitude' => $this->feed->get_latitude(),
            'longitude' => $this->feed->get_longitude(),
            'imageTitle' => $this->feed->get_image_title(),
            'image' => $this->getImage($this->feed->get_image_url()),
            'imageLink' => $this->feed->get_image_link(),
            'imageWidth' => $this->feed->get_image_width(),
            'imageHeight' => $this->feed->get_image_height(),
        ];

        $maxFeedCount = (int)($this->settings['maxFeedCount'] ?? 0);
        foreach ($this->feed->get_items(0, $maxFeedCount) as $item) {
            $data['items'][] = [
                'id' => $item->get_id(),
                'title' => $item->get_title(),
                'content' => $item->get_content(),
                'categories' => $item->get_categories(),
                'authors' => $item->get_authors(),
                'contributors' => $item->get_contributors(),
                'copyright' => $item->get_copyright(),
                'date' => (int)$item->get_date('U'),
                'updatedDate' => (int)$item->get_updated_date('U'),
                'links' => $item->get_links(),
                'enclosures' => $item->get_enclosures(),
                'latitude' => $item->get_latitude(),
                'longitude' => $item->get_longitude(),
                'source' => $item->get_source(),
            ];
        }

        return $data;
    }


    /**
     * @param $fileUrl
     * @return string|null
     */
    private function getImage($fileUrl): ?string
    {
        if ($fileUrl) {
            $urlParts = parse_url($fileUrl);
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

    /**
     * @return void
     */
    private function initFeed(): void
    {
        $feedUrl = $this->settings['feedUrl'] ?? '';
        if (isset($feedUrl) && $feedUrl !== '') {
            if (function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc()) {
                $feedUrl = stripslashes($feedUrl);
            }

            $this->feed->set_feed_url($feedUrl);

            $stripTags = (int)($this->settings['stripTags'] ?? 0);
            if ($stripTags) {
                $this->feed->strip_htmltags();
            }

            $this->feed->enable_cache(false);
            $this->feed->init();
        }
    }
}
