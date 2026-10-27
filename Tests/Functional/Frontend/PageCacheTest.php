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

use ErHaWeb\FeedDisplay\Command\RefreshFeedsCommand;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;

final class PageCacheTest extends AbstractFeedFrontendTestCase
{
    protected function setUp(): void
    {
        // The testing framework disables the page cache by default.
        $this->configurationToUseInTestInstance['SYS']['caching']['cacheConfigurations']['pages']['backend'] = Typo3DatabaseBackend::class;
        parent::setUp();
    }

    #[Test]
    public function refreshCommandFlushesCachedPagesShowingAChangedFeed(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['fetchMode' => 'scheduler']);
        $this->enablePageCache();

        self::assertStringContainsString('Initial title', $this->requestPage());
        self::assertSame(1, $this->countPageCacheEntriesTaggedWith(FeedStorage::getPageCacheTag($feedUrl)));

        $this->writeFeedFixture('Changed title', ['First item']);
        self::assertStringContainsString('Initial title', $this->requestPage());

        (new CommandTester($this->get(RefreshFeedsCommand::class)))->execute([]);

        self::assertSame(0, $this->countPageCacheEntriesTaggedWith(FeedStorage::getPageCacheTag($feedUrl)));
        self::assertStringContainsString('Changed title', $this->requestPage());
    }

    #[Test]
    public function unchangedFeedKeepsCachedPages(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['fetchMode' => 'scheduler']);
        $this->enablePageCache();
        $this->requestPage();

        (new CommandTester($this->get(RefreshFeedsCommand::class)))->execute([]);

        self::assertSame(1, $this->countPageCacheEntriesTaggedWith(FeedStorage::getPageCacheTag($feedUrl)));
    }

    #[Test]
    public function liveModeCachesThePageNoLongerThanCacheDuration(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['cacheDuration' => 600]);
        $this->enablePageCache();
        $this->requestPage();

        $expires = $this->getConnectionPool()
            ->getConnectionForTable('cache_pages')
            ->select(['expires'], 'cache_pages')
            ->fetchOne();

        self::assertIsNumeric($expires);
        self::assertLessThanOrEqual(time() + 600, (int)$expires);
        self::assertGreaterThan(time() + 500, (int)$expires);
    }

    private function enablePageCache(): void
    {
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, 'config.no_cache = 0');
    }

    private function countPageCacheEntriesTaggedWith(string $tag): int
    {
        return $this->getConnectionPool()
            ->getConnectionForTable('cache_pages_tags')
            ->count('*', 'cache_pages_tags', ['tag' => $tag]);
    }
}
