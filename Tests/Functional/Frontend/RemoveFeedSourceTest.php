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
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Console\CommandRegistry;

final class RemoveFeedSourceTest extends AbstractFeedFrontendTestCase
{
    #[Test]
    public function explicitRemovalIsIdempotentAndConfiguredSourcesCanBeCreatedAgain(): void
    {
        $url = $this->writeFeedFixture('Kept source', ['Item']);
        $this->initializeFrontendRootPage($url, ['cacheDuration' => 0]);
        self::assertStringContainsString('Kept source', $this->requestPage());
        $command = new CommandTester($this->get(RemoveFeedSourcesCommand::class));
        self::assertSame(Command::INVALID, $command->execute([]));
        self::assertNotNull($this->get(FeedStorage::class)->find($url));
        self::assertSame(Command::SUCCESS, $command->execute(['--url' => [$url]]));
        self::assertNull($this->get(FeedStorage::class)->find($url));
        self::assertSame(Command::SUCCESS, $command->execute(['--url' => [$url]]));
        self::assertStringContainsString('Kept source', $this->requestPage());
        self::assertNotNull($this->get(FeedStorage::class)->find($url)?->document);
    }

    #[Test]
    public function removalIsExcludedFromTheScheduler(): void
    {
        $commands = iterator_to_array($this->get(CommandRegistry::class)->getSchedulableCommands());
        self::assertArrayHasKey('feeddisplay:refresh', $commands);
        self::assertArrayNotHasKey('feeddisplay:remove', $commands);
    }

    #[Test]
    public function missingSourceStillInvalidatesPagesOnEveryRetry(): void
    {
        $url = 'https://example.test/removed.xml';
        $calls = 0;
        $cache = $this->createMock(CacheManager::class);
        $cache->expects(self::exactly(2))->method('flushCachesInGroupByTag')->with('pages', FeedStorage::cacheTag($url))->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('Temporary cache failure');
            }
        });
        $subject = new FeedRefresher($this->get(FeedStorage::class), $this->get(\ErHaWeb\FeedDisplay\Service\FeedFetcher::class), $this->get(\TYPO3\CMS\Core\Locking\LockFactory::class), $cache, $this->get(\TYPO3\CMS\Core\Context\Context::class), new \Psr\Log\NullLogger());
        self::assertSame('failed', $subject->remove($url)->status);
        self::assertSame('updated', $subject->remove($url)->status);
    }

    #[Test]
    public function busySourcesAreNotRemovedAndCanBeRetried(): void
    {
        $url = $this->writeFeedFixture('Locked source', ['Item']);
        $this->initializeFrontendRootPage($url);
        $this->requestPage();
        $mode = \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $lock = $this->get(\TYPO3\CMS\Core\Locking\LockFactory::class)->createLocker('feed_display_' . FeedStorage::hashUrl($url), $mode);
        self::assertTrue($lock->acquire($mode));
        $command = new CommandTester($this->get(RemoveFeedSourcesCommand::class));
        try {
            self::assertSame(Command::FAILURE, $command->execute(['--url' => [$url]]));
            self::assertStringContainsString('locked:', $command->getDisplay());
            self::assertNotNull($this->get(FeedStorage::class)->find($url)?->document);
        } finally {
            $lock->release();
        }
        self::assertSame(Command::SUCCESS, $command->execute(['--url' => [$url]]));
        self::assertNull($this->get(FeedStorage::class)->find($url));
    }
}
