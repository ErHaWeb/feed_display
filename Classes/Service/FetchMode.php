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
 * Who fetches a feed: page rendering or the feeddisplay:refresh command.
 */
enum FetchMode: string
{
    /** Fetch on page rendering once the stored response is older than cacheDuration. */
    case Live = 'live';
    /** Only render the stored response, the refresh command fetches. */
    case Scheduler = 'scheduler';

    public static function fromSetting(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? trim($value) : '') ?? self::Live;
    }
}
