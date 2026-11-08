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

use ErHaWeb\FeedDisplay\Command\RemoveFeedSourcesCommand;
use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Tests\Functional\Fixtures\FeedHttp;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RemoveFeedPageCacheTest extends AbstractHttpFeedTestCase
{
    #[Test]
    public function removalDuringRenderingDoesNotLeaveTheRemovedSourceCached(): void
    {
        $image = 'https://image.example.test/remove-race.svg';
        FeedHttp::$responses[self::URL] = FeedHttp::rss('OLD_SOURCE', '<image><url>' . $image . '</url><title>I</title><link>https://example.test/</link></image>');
        FeedHttp::$responses[$image] = new Response(200, ['Content-Type' => 'image/svg+xml'], '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>');
        self::assertSame('updated', $this->get(FeedRefresher::class)->refresh(self::URL, true)->status);
        $this->initializeFrontendRootPage(self::URL, ['fetchMode' => 'scheduler', 'cacheDuration' => 0]);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, "config.no_cache = 0\nplugin.tx_feeddisplay_pi1.settings.getFields.feed = title,image_url");
        FeedHttp::$beforeResponse[$image] = function (): void {
            $command = new CommandTester($this->get(RemoveFeedSourcesCommand::class));
            self::assertSame(Command::SUCCESS, $command->execute(['--url' => [self::URL]]));
        };
        self::assertStringContainsString('OLD_SOURCE', $this->requestPage());
        self::assertNull($this->get(FeedStorage::class)->findState(self::URL));
        $pages = $this->getConnectionPool()->getConnectionForTable('cache_pages');
        self::assertSame(0, $pages->count('*', 'cache_pages', []));
        FeedHttp::$responses[self::URL] = FeedHttp::rss('RECREATED_SOURCE');
        self::assertStringContainsString('RECREATED_SOURCE', $this->requestPage());
        self::assertNotNull($this->get(FeedStorage::class)->findState(self::URL));
        self::assertSame(1, $pages->count('*', 'cache_pages', []));
    }
}
