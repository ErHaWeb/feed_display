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

/**
 * @internal
 */
enum RefreshResult: string
{
    /** Fetched successfully, the stored response changed. */
    case Updated = 'updated';
    /** Fetched successfully, the stored response is the same. */
    case Unchanged = 'unchanged';
    /** Fetch failed, the last successful response was kept. */
    case Failed = 'failed';
    /** Another process is fetching this feed right now. */
    case Locked = 'locked';
}
