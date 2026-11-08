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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Frontend;

use ErHaWeb\FeedDisplay\Tests\Functional\Fixtures\FeedHttp;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;

abstract class AbstractHttpFeedTestCase extends AbstractFeedFrontendTestCase
{
    protected const URL = 'https://feed.example.test/rss';

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['SYS']['caching']['cacheConfigurations']['pages']['backend'] = Typo3DatabaseBackend::class;
        parent::setUp();
        FeedHttp::$requests = [];
        FeedHttp::$responses = [];
        // Frontend Bootstrap::init() reloads HTTP configuration from the test instance.
        file_put_contents($this->instancePath . '/typo3conf/system/additional.php', '<?php $GLOBALS[\'TYPO3_CONF_VARS\'][\'HTTP\'][\'handler\'][\'feed-test\'] = \\' . FeedHttp::class . '::middleware(...);');
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler']['feed-test'] = FeedHttp::middleware(...);
    }
}
