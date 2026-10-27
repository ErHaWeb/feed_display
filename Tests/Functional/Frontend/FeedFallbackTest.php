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

use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use PHPUnit\Framework\Attributes\Test;

final class FeedFallbackTest extends AbstractFeedFrontendTestCase
{
    #[Test]
    public function lastSuccessfulResponseIsRenderedWhileTheFeedIsUnavailable(): void
    {
        $feedUrl = $this->writeFeedFixture('Stored feed', ['Stored item']);
        $this->initializeFrontendRootPage($feedUrl, ['cacheDuration' => 0]);

        self::assertStringContainsString('Stored item', $this->requestPage());

        unlink($feedUrl);
        $body = $this->requestPage();

        self::assertStringContainsString('Stored feed', $body);
        self::assertStringContainsString('Stored item', $body);
        self::assertStringNotContainsString('Sorry, the feed could not be fetched.', $body);
    }

    #[Test]
    public function failedFetchOfAnExpiredResponseKeepsTheStoredResponseAndRecordsTheError(): void
    {
        $feedUrl = $this->writeFeedFixture('Stored feed', ['Stored item']);
        $this->initializeFrontendRootPage($feedUrl, ['cacheDuration' => 600]);
        $this->requestPage();
        $storedRow = $this->getStoredRow($feedUrl);

        $this->expireStoredResponse($feedUrl);
        $this->writeFeedXmlFixture('this is not a feed');
        $body = $this->requestPage();

        self::assertStringContainsString('Stored item', $body);
        $row = $this->getStoredRow($feedUrl);
        self::assertSame($storedRow['raw_body'], $row['raw_body']);
        self::assertNotSame('', $row['last_error']);
        self::assertGreaterThan((int)$row['fetched_at'], (int)$row['last_attempt_at']);
    }

    #[Test]
    public function expiredResponseIsReplacedAfterASuccessfulFetch(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['cacheDuration' => 600]);
        $this->requestPage();

        $this->expireStoredResponse($feedUrl);
        $this->writeFeedFixture('Changed title', ['First item']);
        $body = $this->requestPage();

        self::assertStringContainsString('Changed title', $body);
        self::assertSame('', $this->getStoredRow($feedUrl)['last_error']);
    }

    #[Test]
    public function feedThatWasNeverFetchedSuccessfullyDisplaysTheErrorMessage(): void
    {
        $feedUrl = $this->instancePath . '/fileadmin/missing-feed.xml';
        $this->initializeFrontendRootPage($feedUrl);

        $body = $this->requestPage();

        self::assertStringContainsString('Sorry, the feed could not be fetched.', $body);
        $row = $this->getStoredRow($feedUrl);
        self::assertSame('', (string)$row['raw_body']);
        self::assertNotSame('', $row['last_error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getStoredRow(string $feedUrl): array
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable(FeedStorage::TABLE)
            ->select(['*'], FeedStorage::TABLE, ['url_hash' => FeedStorage::hashUrl($feedUrl)])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function expireStoredResponse(string $feedUrl): void
    {
        $this->getConnectionPool()
            ->getConnectionForTable(FeedStorage::TABLE)
            ->update(
                FeedStorage::TABLE,
                ['fetched_at' => 1, 'last_attempt_at' => 1],
                ['url_hash' => FeedStorage::hashUrl($feedUrl)]
            );
    }
}
