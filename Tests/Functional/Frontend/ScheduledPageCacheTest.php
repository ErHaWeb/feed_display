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

use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Tests\Functional\Fixtures\FeedHttp;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

final class ScheduledPageCacheTest extends AbstractHttpFeedTestCase
{
    #[Test]
    #[DataProvider('pluginOrders')]
    public function mixedPluginsCacheOnlyMatchingRevisions(bool $schedulerFirst, bool $anotherScheduler): void
    {
        $this->preparePage();
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $scheduler = $content->select(['*'], 'tt_content', ['uid' => self::CONTENT_ELEMENT_UID])->fetchAssociative();
        self::assertIsArray($scheduler);
        $this->setFlexFormValues(['advanced' => ['settings.fetchMode' => 'live']]);
        $live = $content->select(['*'], 'tt_content', ['uid' => self::CONTENT_ELEMENT_UID])->fetchAssociative();
        self::assertIsArray($live);
        $content->update('tt_content', ['pi_flexform' => $scheduler['pi_flexform']], ['uid' => self::CONTENT_ELEMENT_UID]);
        $live['uid'] = self::CONTENT_ELEMENT_UID + 1;
        $live['sorting'] = $schedulerFirst ? 256 : -256;
        $content->insert('tt_content', $live);
        if ($anotherScheduler) {
            $scheduler['uid'] = self::CONTENT_ELEMENT_UID + 2;
            $scheduler['sorting'] = 512;
            $content->insert('tt_content', $scheduler);
        }
        FeedHttp::$responses[self::URL] = FeedHttp::rss('NEW_SOURCE');
        $first = $this->requestPage();
        self::assertStringContainsString('NEW_SOURCE', $first);
        if ($schedulerFirst) {
            self::assertStringContainsString('OLD_SOURCE', $first);
            self::assertSame([], $this->pageEntries());
        } else {
            self::assertStringNotContainsString('OLD_SOURCE', $first);
            self::assertCount(1, $this->pageEntries());
        }
        self::assertSame('unchanged', $this->get(FeedRefresher::class)->refresh(self::URL, true)->status);
        self::assertStringNotContainsString('OLD_SOURCE', $this->requestPage());
        self::assertCount(1, $this->pageEntries());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function pluginOrders(): iterable
    {
        yield 'scheduler before live' => [true, false];
        yield 'live before scheduler' => [false, false];
        yield 'both revisions of one source' => [true, true];
    }

    #[Test]
    public function refreshDuringRenderingDoesNotLeaveAnObsoletePageCached(): void
    {
        $image = $this->preparePage(true);
        FeedHttp::$beforeResponse[$image] = function (): void {
            FeedHttp::$responses[self::URL] = FeedHttp::rss('NEW_SOURCE');
            self::assertSame('updated', $this->get(FeedRefresher::class)->refresh(self::URL, true)->status);
        };
        self::assertStringContainsString('OLD_SOURCE', $this->requestPage());
        self::assertSame([], $this->pageEntries());
        $stored = $this->get(FeedStorage::class)->findState(self::URL);
        self::assertNotNull($stored);
        self::assertFalse($stored->invalidationPending);
        self::assertSame('unchanged', $this->get(FeedRefresher::class)->refresh(self::URL, true)->status);
        self::assertStringContainsString('NEW_SOURCE', $this->requestPage());
        self::assertCount(1, $this->pageEntries());
    }

    #[Test]
    public function unchangedSourcesKeepTheirPageAndAllRevisionTags(): void
    {
        $this->preparePage();
        $secondUrl = self::URL . '/second';
        FeedHttp::$responses[$secondUrl] = FeedHttp::rss('SECOND_SOURCE');
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $record = $content->select(['*'], 'tt_content', ['uid' => self::CONTENT_ELEMENT_UID])->fetchAssociative();
        self::assertIsArray($record);
        $this->setFlexFormValues(['general' => ['settings.feedUrl' => $secondUrl]]);
        $second = $content->select(['*'], 'tt_content', ['uid' => self::CONTENT_ELEMENT_UID])->fetchAssociative();
        self::assertIsArray($second);
        $content->update('tt_content', ['pi_flexform' => $record['pi_flexform']], ['uid' => self::CONTENT_ELEMENT_UID]);
        $second['uid'] = self::CONTENT_ELEMENT_UID + 1;
        $second['sorting'] = 256;
        $content->insert('tt_content', $second);
        self::assertStringContainsString('SECOND_SOURCE', $this->requestPage());
        $entries = $this->pageEntries();
        self::assertCount(1, $entries);
        self::assertGreaterThan(time() + 3600, (int)$entries[0]['expires']);
        $tags = $this->getConnectionPool()->getConnectionForTable('cache_pages_tags')->select(['tag'], 'cache_pages_tags', ['identifier' => $entries[0]['identifier']])->fetchFirstColumn();
        foreach ([self::URL, $secondUrl] as $url) {
            $state = $this->get(FeedStorage::class)->findState($url);
            self::assertNotNull($state);
            self::assertContains(FeedStorage::cacheTag($url), $tags);
            self::assertContains('feeddisplay_rev_' . FeedStorage::hashUrl($url) . '_' . $state->revision, $tags);
        }
        $requests = FeedHttp::$requests;
        self::assertStringContainsString('OLD_SOURCE', $this->requestPage());
        self::assertSame($requests, FeedHttp::$requests);
        self::assertSame($entries, $this->pageEntries());
    }

    #[Test]
    public function revisionReadFailureKeepsThePageAndLogsADiagnosis(): void
    {
        $image = $this->preparePage(true);
        $log = $this->instancePath . '/typo3temp/revision-errors.log';
        $this->configureLog($log);
        $manager = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->createSchemaManager();
        FeedHttp::$beforeResponse[$image] = static function () use ($manager): void {
            $manager->renameTable(FeedStorage::TABLE, FeedStorage::TABLE . '_unavailable');
        };
        try {
            self::assertStringContainsString('OLD_SOURCE', $this->requestPage());
            self::assertCount(1, $this->pageEntries());
            self::assertFileExists($log);
            $diagnosis = (string)file_get_contents($log);
            self::assertStringContainsString('Could not verify the cached feed revision.', $diagnosis);
            self::assertStringContainsString(FeedStorage::hashUrl(self::URL), $diagnosis);
            self::assertStringContainsString('cacheIdentifier', $diagnosis);
        } finally {
            $manager->renameTable(FeedStorage::TABLE . '_unavailable', FeedStorage::TABLE);
        }
    }

    #[Test]
    public function livePagesDoNotVerifySourceRevisions(): void
    {
        $image = $this->preparePage(true);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, 'plugin.tx_feeddisplay_pi1.settings.fetchMode = live');
        $log = $this->instancePath . '/typo3temp/live-revision-errors.log';
        $this->configureLog($log);
        $manager = $this->getConnectionPool()->getConnectionForTable(FeedStorage::TABLE)->createSchemaManager();
        FeedHttp::$beforeResponse[$image] = static function () use ($manager): void {
            $manager->renameTable(FeedStorage::TABLE, FeedStorage::TABLE . '_unavailable');
        };
        try {
            self::assertStringContainsString('OLD_SOURCE', $this->requestPage());
            self::assertCount(1, $this->pageEntries());
            self::assertStringNotContainsString('Could not verify the cached feed revision.', is_file($log) ? (string)file_get_contents($log) : '');
        } finally {
            $manager->renameTable(FeedStorage::TABLE . '_unavailable', FeedStorage::TABLE);
        }
    }

    private function preparePage(bool $withImage = false): string
    {
        $image = 'https://image.example.test/' . md5($this->nameWithDataSet()) . '.svg';
        $extra = $withImage ? '<image><url>' . $image . '</url><title>Image</title><link>https://example.test/</link></image>' : '';
        FeedHttp::$responses[self::URL] = FeedHttp::rss('OLD_SOURCE', $extra);
        FeedHttp::$responses[$image] = new Response(200, ['Content-Type' => 'image/svg+xml'], '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>');
        self::assertSame('updated', $this->get(FeedRefresher::class)->refresh(self::URL, true)->status);
        $this->initializeFrontendRootPage(self::URL, ['fetchMode' => 'scheduler', 'cacheDuration' => 0]);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, "config.no_cache = 0\nconfig.cache_period = 7200\nplugin.tx_feeddisplay_pi1.settings.getFields.feed = title,image_url");
        return $image;
    }

    private function configureLog(string $path): void
    {
        $writers = [LogLevel::WARNING => [FileWriter::class => ['logFile' => $path]]];
        file_put_contents($this->instancePath . '/typo3conf/system/additional.php', "\n\$GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = " . var_export($writers, true) . ';', FILE_APPEND);
    }

    /** @return list<array<string, mixed>> */
    private function pageEntries(): array
    {
        return $this->getConnectionPool()->getConnectionForTable('cache_pages')->select(['identifier', 'expires'], 'cache_pages')->fetchAllAssociative();
    }
}
