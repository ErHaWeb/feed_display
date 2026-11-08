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

use ErHaWeb\FeedDisplay\Storage\FeedDocument;
use SimplePie\Misc;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/** @internal Fetches and replays documents without display settings or events. */
final class FeedFetcher
{
    public function __construct(
        private readonly FeedRuntimeInitializer $initializer,
        private readonly GuzzleClientFactory $clientFactory,
    ) {}

    public function fetch(string $url, bool $bounded = false): FetchedFeed
    {
        $feed = new FeedParser();
        $recorder = null;
        // SimplePie fetches feed:// and host-only values over HTTP, too.
        $target = SimplePieDeprecationHandler::run(static fn(): string => Misc::fix_protocol($url, 1));
        if (Misc::is_remote_uri($target)) {
            $url = $target;
            $recorder = new FeedHttpRecorder(
                $this->clientFactory->getClient(),
                $bounded ? $this->timeout('timeout') : null,
                $bounded ? $this->timeout('connect_timeout') : null,
                $bounded ? FeedHttpRecorder::MAX_BODY_SIZE : null,
            );
        }
        if ($bounded) {
            // Scheduled fetches read one direct source, which keeps their limits predictable.
            $feed->set_autodiscovery_level(SimplePie::LOCATOR_NONE);
            if ($recorder === null && (!is_file($url) || filesize($url) > FeedHttpRecorder::MAX_BODY_SIZE)) {
                throw new \RuntimeException('The local feed source must be a file of at most 2 MiB.');
            }
        }
        $initialized = SimplePieDeprecationHandler::run(
            fn(): bool => $this->initializer->initializeFeed($feed, ['feedUrl' => $url], $recorder)
        );
        if (!$initialized) {
            $error = $feed->error() ?? '';
            if (is_array($error)) {
                $error = implode('; ', $error);
            }
            throw new \RuntimeException($error !== '' ? $error : 'The source did not return a usable feed.');
        }
        $snapshot = $feed->getSnapshot();
        // Every stored byte must have passed the recorder's size accounting.
        if ($recorder !== null && !$recorder->received($snapshot['response']['body'])) {
            throw new \RuntimeException('The selected feed is not part of the recorded responses.');
        }
        $document = new FeedDocument(['version' => 2, 'kind' => 'snapshot', 'snapshot' => $snapshot]);
        return new FetchedFeed($feed, $document);
    }

    public function restore(FeedDocument $document): SimplePie
    {
        $feed = new FeedParser($document->payload['snapshot']);
        if (!SimplePieDeprecationHandler::run($feed->init(...))) {
            throw new \UnexpectedValueException('The stored feed could not be parsed.');
        }
        return $feed;
    }

    private function timeout(string $key): float
    {
        $configured = (float)($GLOBALS['TYPO3_CONF_VARS']['HTTP'][$key] ?? 0);
        return $configured > 0 ? min($configured, 10.0) : 10.0;
    }
}
