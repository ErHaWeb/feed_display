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

namespace ErHaWeb\FeedDisplay\Tests\Fixtures;

use ErHaWeb\FeedDisplay\Storage\FeedDocument;

final class StoredDocument
{
    public static function create(string $body, string $uri = 'https://feed.example.test/rss'): FeedDocument
    {
        return new FeedDocument(['version' => 2, 'kind' => 'snapshot', 'snapshot' => [
            'response' => ['uri' => $uri, 'base' => $uri, 'status' => 200, 'headers' => ['content-type' => ['application/rss+xml']], 'body' => $body],
            'mime' => 'application/rss+xml',
        ]]);
    }
}
