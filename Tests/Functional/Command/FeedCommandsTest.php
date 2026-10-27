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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Command;

use ErHaWeb\FeedDisplay\Command\PruneFeedsCommand;
use ErHaWeb\FeedDisplay\Command\RefreshFeedsCommand;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FeedCommandsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'erhaweb/feed-display',
    ];

    private FeedStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/Pages.csv');
        $this->storage = $this->get(FeedStorage::class);
    }

    #[Test]
    public function refreshFetchesConfiguredRecentlyUsedAndAdditionalFeeds(): void
    {
        $configuredUrl = $this->writeFeed('configured.xml', 'Configured feed');
        $usedUrl = $this->writeFeed('used.xml', 'Used feed');
        $additionalUrl = $this->writeFeed('additional.xml', 'Additional feed');
        $this->addContentElement(100, $configuredUrl);
        $this->storage->markUsed($usedUrl, time());

        $tester = $this->runCommand(RefreshFeedsCommand::class, ['--url' => [$additionalUrl]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        foreach ([$configuredUrl, $usedUrl, $additionalUrl] as $url) {
            self::assertTrue($this->storage->find($url)?->hasContent(), $url . ' should have been fetched');
        }
    }

    #[Test]
    public function refreshSkipsFeedsNotUsedRecentlyAndDeletedContentElements(): void
    {
        $oldUrl = $this->writeFeed('old.xml', 'Old feed');
        $deletedUrl = $this->writeFeed('deleted.xml', 'Deleted feed');
        $this->storage->markUsed($oldUrl, time() - 40 * 86400);
        $this->addContentElement(100, $deletedUrl, deleted: true);

        $tester = $this->runCommand(RefreshFeedsCommand::class);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFalse($this->storage->find($oldUrl)?->hasContent());
        self::assertNull($this->storage->find($deletedUrl));
    }

    #[Test]
    public function refreshKeepsTheStoredResponseOfAnUnavailableFeedAndFails(): void
    {
        $url = $this->writeFeed('feed.xml', 'Stored feed');
        $this->addContentElement(100, $url);
        $this->runCommand(RefreshFeedsCommand::class);
        $storedBody = $this->storage->find($url)?->rawBody;

        unlink($url);
        $tester = $this->runCommand(RefreshFeedsCommand::class);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('failed', $tester->getDisplay());
        self::assertSame($storedBody, $this->storage->find($url)?->rawBody);
        self::assertNotSame('', $this->storage->find($url)?->lastError);
    }

    #[Test]
    public function pruneRemovesUnusedFeedsButKeepsConfiguredOnes(): void
    {
        $this->addContentElement(100, 'https://example.com/configured.xml');
        $this->storage->markUsed('https://example.com/configured.xml', time() - 40 * 86400);
        $this->storage->markUsed('https://example.com/old.xml', time() - 40 * 86400);
        $this->storage->markUsed('https://example.com/recent.xml', time() - 5 * 86400);

        $tester = $this->runCommand(PruneFeedsCommand::class);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNull($this->storage->find('https://example.com/old.xml'));
        self::assertNotNull($this->storage->find('https://example.com/recent.xml'));
        self::assertNotNull($this->storage->find('https://example.com/configured.xml'));
    }

    #[Test]
    public function pruneHonoursTheMaximumAge(): void
    {
        $this->storage->markUsed('https://example.com/recent.xml', time() - 5 * 86400);

        $this->runCommand(PruneFeedsCommand::class, ['--max-age-days' => '3']);

        self::assertNull($this->storage->find('https://example.com/recent.xml'));
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, mixed> $input
     */
    private function runCommand(string $commandClass, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->get($commandClass));
        $tester->execute($input);

        return $tester;
    }

    private function writeFeed(string $fileName, string $title): string
    {
        $path = $this->instancePath . '/fileadmin/' . $fileName;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents(
            $path,
            sprintf(
                '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>%s</title><link>https://example.com/</link><description>Test</description><item><title>Item</title></item></channel></rss>',
                $title
            )
        );

        return $path;
    }

    private function addContentElement(int $uid, string $feedUrl, bool $deleted = false): void
    {
        $flexForm = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="general"><language index="lDEF">'
            . '<field index="settings.feedUrl"><value index="vDEF">' . htmlspecialchars($feedUrl, ENT_XML1) . '</value></field>'
            . '</language></sheet></data></T3FlexForms>';

        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => $uid,
            'pid' => 1,
            'CType' => 'feeddisplay_pi1',
            'deleted' => (int)$deleted,
            'pi_flexform' => $flexForm,
        ]);
    }
}
