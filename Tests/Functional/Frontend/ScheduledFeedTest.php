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
use ErHaWeb\FeedDisplay\Service\ConfiguredFeedUrls;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\Backend\TaggableBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;

final class ScheduledFeedTest extends AbstractFeedFrontendTestCase
{
    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['SYS']['caching']['cacheConfigurations']['pages']['backend'] = Typo3DatabaseBackend::class;
        parent::setUp();
    }

    #[Test]
    public function firstRequestBootstrapsAndLaterRequestsOnlyUseTheStoredSource(): void
    {
        $url = $this->writeFeedFixture('First response', ['First item']);
        $this->initializeFrontendRootPage($url, ['fetchMode' => 'scheduler', 'cacheDuration' => 0]);
        self::assertStringContainsString('First response', $this->requestPage());
        $storage = $this->get(FeedStorage::class);
        $first = $storage->find($url);
        self::assertNotNull($first?->document);
        $this->writeFeedFixture('New response', ['Second item']);
        $this->get(CacheManager::class)->flushCaches();
        self::assertStringContainsString('First response', $this->requestPage());
        self::assertSame($first->revision, $storage->find($url)?->revision);
        $command = new CommandTester($this->get(RefreshFeedsCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('updated:', $command->getDisplay());
        self::assertStringContainsString('New response', $this->requestPage());
        unlink($url);
        $this->get(CacheManager::class)->flushCaches();
        self::assertStringContainsString('New response', $this->requestPage());
    }

    #[Test]
    public function failedBootstrapIsThrottledAndTheMissingPageExpiresWithinSixtySeconds(): void
    {
        $url = $this->instancePath . '/fileadmin/missing.xml';
        $this->initializeFrontendRootPage($url, ['fetchMode' => 'scheduler']);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, 'config.no_cache = 0');
        self::assertStringContainsString('error-no-feed', $this->requestPage());
        $pages = $this->taggedPages($url);
        self::assertCount(1, $pages);
        $expires = $this->getConnectionPool()->getConnectionForTable('cache_pages')->select(['expires'], 'cache_pages', ['identifier' => $pages[0]])->fetchOne();
        self::assertLessThanOrEqual(time() + 60, (int)$expires);
        $this->writeFeedXmlFixture('<rss version="2.0"><channel><title>Recovered</title><description>Test</description></channel></rss>', 'missing.xml');
        $this->get(CacheManager::class)->flushCaches();
        self::assertStringContainsString('error-no-feed', $this->requestPage());
        $command = new CommandTester($this->get(RefreshFeedsCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertSame([], $this->taggedPages($url));
        self::assertStringContainsString('Recovered', $this->requestPage());
    }

    #[Test]
    public function commandDeduplicatesAllSourcesAndContinuesAfterOneFails(): void
    {
        $url = $this->writeFeedFixture('Selected', ['Item']);
        $this->setFlexFormValues(['general' => ['settings.feedUrl' => $url]]);
        $storage = $this->get(FeedStorage::class);
        $storage->storeFailure($url, 'Previous failure', 1);
        $missing = $this->instancePath . '/fileadmin/absent.xml';
        self::assertSame([$url, $missing], $this->get(ConfiguredFeedUrls::class)->collect([$missing, $url]));
        $command = new CommandTester($this->get(RefreshFeedsCommand::class));
        self::assertSame(Command::FAILURE, $command->execute(['--url' => [$missing, $url]]));
        self::assertSame(1, substr_count($command->getDisplay(), 'updated:'));
        self::assertStringContainsString('failed:', $command->getDisplay());
        self::assertNotNull($storage->find($url)?->document);
    }

    #[Test]
    public function changedSourceInvalidatesPagesWithoutShorteningTheirNormalLifetime(): void
    {
        $url = $this->writeFeedFixture('Original', ['Item']);
        $this->initializeFrontendRootPage($url, ['fetchMode' => 'scheduler', 'cacheDuration' => -1]);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, "config.no_cache = 0\nconfig.cache_period = 7200");
        self::assertStringContainsString('Original', $this->requestPage());
        $pages = $this->taggedPages($url);
        self::assertCount(1, $pages);
        $expires = $this->getConnectionPool()->getConnectionForTable('cache_pages')->select(['expires'], 'cache_pages', ['identifier' => $pages[0]])->fetchOne();
        self::assertGreaterThan(time() + 3600, (int)$expires, json_encode([$pages, $this->getConnectionPool()->getConnectionForTable('cache_pages')->select(['identifier', 'expires'], 'cache_pages')->fetchAllAssociative()], JSON_THROW_ON_ERROR));
        $this->writeFeedFixture('Changed', ['Item']);
        $command = new CommandTester($this->get(RefreshFeedsCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertSame([], $this->taggedPages($url));
        self::assertStringContainsString('Changed', $this->requestPage());
    }

    #[Test]
    public function emptySourceListIsSuccessful(): void
    {
        $command = new CommandTester($this->get(RefreshFeedsCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('No feed sources found', $command->getDisplay());
    }

    #[Test]
    public function twoPluginsShareOneSourceButKeepSeparateDisplayArrays(): void
    {
        $url = $this->writeFeedFixture('Shared source', ['One', 'Two']);
        $this->initializeFrontendRootPage($url, ['fetchMode' => 'scheduler', 'cacheDuration' => 600, 'maxFeedCount' => 2]);
        $this->setFlexFormValues(['general' => ['settings.maxFeedCount' => 1]]);
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $record = $connection->select(['*'], 'tt_content', ['uid' => self::CONTENT_ELEMENT_UID])->fetchAssociative();
        self::assertIsArray($record);
        $record['uid'] = self::CONTENT_ELEMENT_UID + 1;
        $record['sorting'] = 256;
        $record['pi_flexform'] = '';
        $connection->insert('tt_content', $record);
        $body = $this->requestPage();
        self::assertSame(2, substr_count($body, 'Shared source'));
        self::assertSame(3, substr_count($body, 'class="item-title"'));
        self::assertSame(1, $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->count('*', FeedStorage::TABLE, []));
        self::assertSame(2, $this->getConnectionPool()->getConnectionForTable('cache_feeddisplay')->count('*', 'cache_feeddisplay', []));
        $this->writeFeedFixture('Shared update', ['Three', 'Four']);
        $command = new CommandTester($this->get(RefreshFeedsCommand::class));
        self::assertSame(Command::SUCCESS, $command->execute([]));
        $body = $this->requestPage();
        self::assertSame(2, substr_count($body, 'Shared update'));
        self::assertSame(3, substr_count($body, 'class="item-title"'));
        self::assertStringNotContainsString('Shared source', $body);
    }

    #[Test]
    public function schedulerWithoutTheSchemaShowsTheConfiguredError(): void
    {
        $url = $this->writeFeedFixture('Not fetched', ['Item']);
        $this->initializeFrontendRootPage($url, ['fetchMode' => 'scheduler', 'errorMessage' => 'Schema not ready']);
        $manager = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->createSchemaManager();
        $manager->renameTable(FeedStorage::TABLE, FeedStorage::TABLE . '_unavailable');
        try {
            self::assertStringContainsString('Schema not ready', $this->requestPage());
        } finally {
            $manager->renameTable(FeedStorage::TABLE . '_unavailable', FeedStorage::TABLE);
        }
    }

    #[Test]
    public function busyFirstBootstrapCanBeRetriedAfterTheOtherWorkerReleasesItsLock(): void
    {
        $url = $this->writeFeedFixture('Available after lock', ['Item']);
        $this->initializeFrontendRootPage($url, ['fetchMode' => 'scheduler']);
        $mode = \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | \TYPO3\CMS\Core\Locking\LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        $lock = $this->get(\TYPO3\CMS\Core\Locking\LockFactory::class)->createLocker('feed_display_' . FeedStorage::hashUrl($url), $mode);
        self::assertTrue($lock->acquire($mode));
        try {
            self::assertStringContainsString('error-no-feed', $this->requestPage());
            self::assertNull($this->get(FeedStorage::class)->find($url));
        } finally {
            $lock->release();
        }
        self::assertStringContainsString('Available after lock', $this->requestPage());
    }

    #[Test]
    public function sourceSelectionIncludesHiddenContentButSkipsDeletedWorkspaceAndMalformedRecords(): void
    {
        $url = $this->instancePath . '/fileadmin/selected.xml';
        $this->setFlexFormValues(['advanced' => ['settings.feedUrl' => $url]]);
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $record = $content->select(['*'], 'tt_content', ['uid' => self::CONTENT_ELEMENT_UID])->fetchAssociative();
        self::assertIsArray($record);
        $content->update('tt_content', ['hidden' => 1], ['uid' => self::CONTENT_ELEMENT_UID]);
        foreach (['deleted' => 1, 't3ver_wsid' => 1] as $field => $value) {
            ++$record['uid'];
            $copy = $record;
            $copy[$field] = $value;
            $copy['pi_flexform'] = str_replace('selected.xml', $field . '.xml', (string)$copy['pi_flexform']);
            $content->insert('tt_content', $copy);
        }
        ++$record['uid'];
        $record['pi_flexform'] = '<broken';
        $content->insert('tt_content', $record);
        $additional = 'https://example.test/feed?ids=1,2';
        self::assertSame([$url, $additional], $this->get(ConfiguredFeedUrls::class)->collect([' ' . $additional . ' ', $url, $additional]));
    }

    #[Test]
    #[DataProvider('emptyFlexForms')]
    public function emptyFlexFormsAreIgnoredWhileMalformedXmlIsDiagnosed(?string $flexForm): void
    {
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Could not read feed FlexForm.', self::anything());
        $collector = new ConfiguredFeedUrls($this->getConnectionPool(), $this->get(FeedStorage::class), $logger);
        $content->update('tt_content', ['pi_flexform' => $flexForm], ['uid' => self::CONTENT_ELEMENT_UID]);
        self::assertSame([], $collector->collect([]));
        $content->update('tt_content', ['pi_flexform' => '<broken'], ['uid' => self::CONTENT_ELEMENT_UID]);
        self::assertSame([], $collector->collect([]));
    }

    /** @return iterable<string, array{?string}> */
    public static function emptyFlexForms(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
    }

    /** @return list<string> */
    private function taggedPages(string $url): array
    {
        $backend = $this->get(CacheManager::class)->getCache('pages')->getBackend();
        self::assertInstanceOf(TaggableBackendInterface::class, $backend);
        return array_values($backend->findIdentifiersByTag(FeedStorage::cacheTag($url)));
    }
}
