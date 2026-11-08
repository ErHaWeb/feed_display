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
use ErHaWeb\FeedDisplay\Service\FeedHttpRecorder;
use ErHaWeb\FeedDisplay\Service\FeedRuntimeInitializer;
use ErHaWeb\FeedDisplay\Service\FeedValueNormalizer;
use ErHaWeb\FeedDisplay\Service\SimplePieDeprecationHandler;
use ErHaWeb\FeedDisplay\Storage\FeedDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedSnapshotTest extends UnitTestCase
{
    private const URL = 'https://example.test/discovery';

    /** @param list<Response> $responses */
    private function fetcher(array $responses): FeedFetcher
    {
        $factory = $this->createMock(GuzzleClientFactory::class);
        $factory->expects(self::once())->method('getClient')->willReturn(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        $http = new HttpFactory();
        return new FeedFetcher(new FeedRuntimeInitializer($factory, $http, $http), $factory);
    }

    private static function rss(string $title): string
    {
        return '<rss version="2.0"><channel><title>' . $title . '</title><description>Test</description><item><title>Item</title><link>article.html</link></item></channel></rss>';
    }

    /** @param list<Response> $responses */
    #[Test]
    #[DataProvider('responses')]
    public function snapshotRetainsSelectedFeedAndItsMetadata(array $responses): void
    {
        $fetcher = $this->fetcher($responses);
        $fetched = $fetcher->fetch(self::URL);
        $document = FeedDocument::decode($fetched->document->encode());
        $replay = $fetcher->restore($document);
        $values = static fn(SimplePie $feed): array => SimplePieDeprecationHandler::run(static fn(): array => [
            $feed->get_title(), $feed->subscribe_url(), $feed->subscribe_url(true), $feed->get_base(),
            $feed->get_language(), $feed->get_link(0, 'self'), $feed->get_item(0)?->get_permalink(),
            $feed->get_item(0)?->get_title(), (new FeedValueNormalizer())->normalizeValue($feed->get_all_discovered_feeds()),
            $feed->data['headers'] ?? [],
        ]);
        self::assertSame($values($fetched->feed), $values($replay));
        self::assertSame($fetched->document->revision(), $document->revision());
    }

    /** @return iterable<string, array{list<Response>}> */
    public static function responses(): iterable
    {
        yield from FeedHttpTest::feedResponses();
        $html = '<html><head><link rel="alternate" type="application/rss+xml" href="https://example.test/a"><link rel="alternate" type="application/rss+xml" href="https://example.test/b"></head></html>';
        yield 'two discovered feeds retain the first selected feed' => [[
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'application/rss+xml'], self::rss('SELECTED_A')),
            new Response(200, ['Content-Type' => 'application/rss+xml'], self::rss('ALTERNATIVE_B')),
        ]];
        yield 'trailing non-feed success does not replace the selected feed' => [[
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'application/rss+xml'], self::rss('SELECTED_A')),
            new Response(200, ['Content-Type' => 'text/html'], '<html>No feed here</html>'),
        ]];
    }

    #[Test]
    public function boundedFetchesUseOnlyTheDirectSource(): void
    {
        $html = '<html><head><link rel="alternate" type="application/rss+xml" href="https://example.test/a"></head></html>';
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
            new Response(200, ['Content-Type' => 'application/rss+xml'], self::rss('NOT_REQUESTED')),
        ]);
        $factory = $this->createMock(GuzzleClientFactory::class);
        $factory->method('getClient')->willReturn(new Client(['handler' => HandlerStack::create($mock)]));
        $http = new HttpFactory();
        $fetcher = new FeedFetcher(new FeedRuntimeInitializer($factory, $http, $http), $factory);
        try {
            $fetcher->fetch(self::URL, true);
            self::fail('A bounded fetch must not continue with another source.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('could not be found', $exception->getMessage());
        }
        self::assertCount(1, $mock);
    }

    #[Test]
    public function boundedLocalSourcesMustBeFilesWithinTheLimit(): void
    {
        $factory = $this->createMock(GuzzleClientFactory::class);
        $factory->expects(self::never())->method('getClient');
        $http = new HttpFactory();
        $fetcher = new FeedFetcher(new FeedRuntimeInitializer($factory, $http, $http), $factory);
        $path = tempnam(sys_get_temp_dir(), 'feed-snapshot-');
        self::assertIsString($path);
        try {
            file_put_contents($path, str_repeat(' ', FeedHttpRecorder::MAX_BODY_SIZE + 1));
            foreach ([$path, sys_get_temp_dir()] as $source) {
                try {
                    $fetcher->fetch($source, true);
                    self::fail('The source must be rejected.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('at most 2 MiB', $exception->getMessage());
                }
            }
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function httpSourcesWithoutHttpScheme(): iterable
    {
        yield 'feed scheme' => ['feed://example.test/feed'];
        yield 'host only' => ['example.test'];
    }

    #[Test]
    #[DataProvider('httpSourcesWithoutHttpScheme')]
    public function httpSourcesWithoutHttpSchemeAreRecorded(string $source): void
    {
        $fetched = $this->fetcher([new Response(200, ['Content-Type' => 'application/rss+xml'], self::rss('RECORDED'))])->fetch($source, true);
        self::assertSame('RECORDED', SimplePieDeprecationHandler::run($fetched->feed->get_title(...)));
    }

    #[Test]
    public function recorderConfirmsOnlyReceivedBodies(): void
    {
        $http = new HttpFactory();
        $recorder = new FeedHttpRecorder(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], 'RECEIVED')]))]));
        $recorder->sendRequest($http->createRequest('GET', self::URL));
        self::assertTrue($recorder->received('RECEIVED'));
        self::assertFalse($recorder->received('OTHER'));
    }
}
