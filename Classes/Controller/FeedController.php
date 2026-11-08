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

namespace ErHaWeb\FeedDisplay\Controller;

use ErHaWeb\FeedDisplay\Service\FeedProvider;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class FeedController extends ActionController
{
    public function __construct(private readonly FeedProvider $feedProvider) {}

    public function displayAction(): ResponseInterface
    {
        $scheduler = trim((string)($this->settings['fetchMode'] ?? 'live')) === 'scheduler';
        if (array_key_exists('fetchMode', $this->settings)) {
            unset($this->settings['fetchMode']);
            $this->view->assign('settings', $this->settings);
        }
        if ($this->settings === []) {
            return $this->htmlResponse();
        }

        $url = trim(stripslashes((string)($this->settings['feedUrl'] ?? '')));
        $collector = $this->request->getAttribute('frontend.cache.collector');
        if ($scheduler && $url !== '' && $collector instanceof CacheDataCollector) {
            $collector->addCacheTags(new CacheTag(FeedStorage::cacheTag($url)));
        }
        $provided = $this->feedProvider->provide($this->settings, $scheduler);
        $data = $provided->data;
        if ($scheduler && $url !== '' && $provided->revision !== null && $collector instanceof CacheDataCollector) {
            $collector->addCacheTags(new CacheTag(FeedStorage::revisionTag($url, $provided->revision)));
        }
        if ($scheduler && !isset($data['feed']) && !isset($data['items']) && $collector instanceof CacheDataCollector) {
            $collector->restrictMaximumLifetime(60);
        }
        $this->view->assign('data', $data);
        return $this->htmlResponse();
    }
}
