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

use SimplePie\SimplePie;

/**
 * Fetches a feed and validates it with SimplePie, without building any
 * display data.
 *
 * @internal
 */
class FeedFetcher
{
    public function __construct(
        private readonly FeedRuntimeInitializer $initializer,
    ) {}

    public function fetch(string $url): FetchResult
    {
        // A fresh instance keeps fetching independent of the shared one used for rendering.
        $feed = new SimplePie();

        try {
            $success = SimplePieDeprecationHandler::run(
                fn(): bool => $this->initializer->initializeFeed($feed, ['feedUrl' => $url])
            );
        } catch (\Throwable $exception) {
            return FetchResult::failure($exception->getMessage());
        }

        $rawData = $feed->get_raw_data();
        if (!$success || !is_string($rawData) || $rawData === '') {
            return FetchResult::failure($this->getErrorMessage($feed));
        }

        return FetchResult::success($rawData);
    }

    private function getErrorMessage(SimplePie $feed): string
    {
        $error = $feed->error();
        if (is_array($error)) {
            $error = implode(' ', array_filter($error, is_string(...)));
        }

        return is_string($error) && $error !== '' ? $error : 'The feed could not be fetched.';
    }
}
