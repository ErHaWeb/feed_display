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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Fixtures\EventListener;

use ErHaWeb\FeedDisplay\Event\SingleFeedDataEvent;

final class ModifySingleFeedDataListener
{
    public function __invoke(SingleFeedDataEvent $event): void
    {
        $itemProperties = $event->getItemProperties();
        $title = (string)($itemProperties['title'] ?? '');

        if ($title === 'Second item') {
            $event->setItemProperties([]);

            return;
        }

        if ($title === 'First item') {
            $itemProperties['title'] = 'First item (modified)';
            $event->setItemProperties($itemProperties);
        }
    }
}
