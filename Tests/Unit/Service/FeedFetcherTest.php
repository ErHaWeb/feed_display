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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Service;

use ErHaWeb\FeedDisplay\Service\FeedFetcher;
use ErHaWeb\FeedDisplay\Service\FeedRuntimeInitializer;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedFetcherTest extends UnitTestCase
{
    private const FEED_XML = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Fetched feed</title><link>https://example.com/</link><description>Test</description><item><title>Item</title></item></channel></rss>';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/feed-display-fetcher-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');
        array_map(unlink(...), $files === false ? [] : $files);
        rmdir($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function validFeedIsReturnedAsRawData(): void
    {
        $path = $this->directory . '/feed.xml';
        file_put_contents($path, self::FEED_XML);

        $result = $this->createSubject()->fetch($path);

        self::assertTrue($result->success);
        self::assertSame(self::FEED_XML, $result->rawData);
        self::assertSame('', $result->error);
    }

    #[Test]
    public function missingFeedIsReportedWithSimplePieError(): void
    {
        $result = $this->createSubject()->fetch($this->directory . '/missing.xml');

        self::assertFalse($result->success);
        self::assertSame('', $result->rawData);
        self::assertNotSame('', $result->error);
    }

    #[Test]
    public function invalidFeedIsReportedAsFailure(): void
    {
        $path = $this->directory . '/invalid.xml';
        file_put_contents($path, '<html><body>No feed here</body></html>');

        $result = $this->createSubject()->fetch($path);

        self::assertFalse($result->success);
        self::assertNotSame('', $result->error);
    }

    private function createSubject(): FeedFetcher
    {
        return new FeedFetcher(new FeedRuntimeInitializer(
            $this->createMock(GuzzleClientFactory::class),
            $this->createMock(RequestFactoryInterface::class),
            $this->createMock(UriFactoryInterface::class),
        ));
    }
}
