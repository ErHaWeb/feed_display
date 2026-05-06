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

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * @internal
 * @phpstan-type FeedSettings array<string, mixed>
 */
final class FeedRuntimeInitializer
{
    public function __construct(
        private readonly GuzzleClientFactory $guzzleClientFactory,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly UriFactoryInterface $uriFactory,
    ) {}

    /**
     * @param FeedSettings $settings
     */
    public function initializeFeed(SimplePie $feed, array $settings): bool
    {
        $feedUrl = $settings['feedUrl'] ?? '';
        if ($feedUrl === '') {
            return false;
        }

        $feedUrl = stripslashes((string)$feedUrl);
        $feed->set_feed_url($feedUrl);
        if ($this->shouldUseTypo3HttpClient($feedUrl)) {
            $httpClient = $this->guzzleClientFactory->getClient();
            assert($httpClient instanceof \Psr\Http\Client\ClientInterface);
            $feed->set_http_client($httpClient, $this->requestFactory, $this->uriFactory);
        }
        $feed->enable_cache(false);
        // Feed autodiscovery can hit SimplePie's IRI handling before getters run.
        SimplePieDeprecationHandler::run($feed->init(...));

        return true;
    }

    private function shouldUseTypo3HttpClient(string $feedUrl): bool
    {
        $scheme = parse_url($feedUrl, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
