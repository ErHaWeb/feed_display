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

use ErHaWeb\FeedDisplay\Event\SingleFeedDataEvent;
use ErHaWeb\FeedDisplay\Service\FeedDataService;
use ErHaWeb\FeedDisplay\Service\FeedHttpRecorder;
use ErHaWeb\FeedDisplay\Service\FeedRuntimeInitializer;
use ErHaWeb\FeedDisplay\Service\SimplePieDeprecationHandler;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedHttpTest extends UnitTestCase
{
    private const URL = 'https://example.test/feed.xml';

    /**
     * @param list<Response> $responses
     */
    #[Test]
    #[DataProvider('feedResponses')]
    public function recordingAndReplayMatchLiveParsing(array $responses): void
    {
        $liveClient = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $guzzleFactory = $this->createMock(GuzzleClientFactory::class);
        $guzzleFactory->expects(self::once())->method('getClient')->willReturn($liveClient);
        $httpFactory = new HttpFactory();
        $initializer = new FeedRuntimeInitializer($guzzleFactory, $httpFactory, $httpFactory);
        $live = new SimplePie();
        self::assertTrue($initializer->initializeFeed($live, ['feedUrl' => self::URL]));

        $recordingClient = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $recordingFactory = self::createStub(GuzzleClientFactory::class);
        $recordingFactory->method('getClient')->willReturn($recordingClient);
        $fetcher = new \ErHaWeb\FeedDisplay\Service\FeedFetcher(new FeedRuntimeInitializer($recordingFactory, $httpFactory, $httpFactory), $recordingFactory);
        $fetched = $fetcher->fetch(self::URL);
        $recorded = $fetched->feed;
        $replayed = $fetcher->restore(\ErHaWeb\FeedDisplay\Storage\FeedDocument::decode($fetched->document->encode()));

        $liveData = $this->getFeedValues($live);
        self::assertSame($liveData, $this->getFeedValues($recorded));
        self::assertSame($liveData, $this->getFeedValues($replayed));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (SingleFeedDataEvent $event) use ($replayed): SingleFeedDataEvent {
                self::assertSame($replayed, $event->getFeed());
                self::assertSame($replayed->get_item(0), $event->getItem());
                return $event;
            }
        );
        (new FeedDataService($live, $dispatcher, $initializer))->buildInitializedData($replayed, [
            'getFields' => ['items' => 'title'],
            'maxFeedCount' => 1,
        ]);
    }

    /**
     * @return iterable<string, array{list<Response>}>
     */
    public static function feedResponses(): iterable
    {
        $rss = '<rss version="2.0"><channel><title>%s</title><description>Test</description>'
            . '<item><title>Item</title><link>article.html</link></item></channel></rss>';
        $atom = '<feed xmlns="http://www.w3.org/2005/Atom" xml:base="/articles/"><title>News</title>'
            . '<entry><title>Item</title><id>urn:news:1</id><link href="article.html"/></entry></feed>';

        foreach ([301, 302] as $status) {
            yield 'RSS redirect ' . $status => [[
                new Response($status, ['Location' => 'https://new.example.test/rss/feed.xml']),
                new Response(200, ['Content-Type' => 'application/rss+xml'], sprintf($rss, 'News')),
            ]];
            yield 'Atom redirect ' . $status => [[
                new Response($status, ['Location' => 'https://new.example.test/atom/feed.xml']),
                new Response(200, ['Content-Type' => 'application/atom+xml'], $atom),
            ]];
        }

        yield 'HTML feed autodiscovery' => [[
            new Response(200, ['Content-Type' => 'text/html'], '<html><head><link rel="alternate" type="application/rss+xml" href="/discovered/feed.xml"></head><body>News</body></html>'),
            new Response(200, ['Content-Type' => 'application/rss+xml'], sprintf($rss, 'Discovered feed')),
        ]];

        yield 'non-UTF-8 body' => [[new Response(
            200,
            ['Content-Type' => 'application/rss+xml'],
            '<?xml version="1.0" encoding="ISO-8859-1"?>' . sprintf($rss, "Stra\xDFe")
        )]];
        yield 'encoding declared in lowercase header' => [[new Response(
            200,
            ['content-type' => 'application/rss+xml; charset=windows-1252'],
            sprintf($rss, "Price 10 \x80")
        )]];
        yield 'encoding declared in mixed case header' => [[new Response(
            200,
            ['Content-Type' => 'application/rss+xml; charset=windows-1252'],
            sprintf($rss, "Price 10 \x80")
        )]];
        yield 'encoding contradicts body' => [[new Response(
            200,
            ['Content-Type' => 'application/rss+xml; charset=ISO-8859-1'],
            sprintf($rss, 'Straße')
        )]];
        foreach ([['Link', 'Content-Language'], ['link', 'content-language']] as [$linkHeader, $languageHeader]) {
            yield $linkHeader . ' and ' . $languageHeader => [[new Response(
                200,
                [
                    'Content-Type' => 'application/rss+xml',
                    $linkHeader => '<https://example.test/canonical/feed.xml>; rel="self"',
                    $languageHeader => 'de',
                    'Set-Cookie' => 'not-stored=1',
                ],
                sprintf($rss, 'News')
            )]];
        }
    }

    #[Test]
    public function recorderReadsOnlyTheRemainingBodyBudgetAndClosesTheStream(): void
    {
        $readBytes = 0;
        $stream = new PumpStream(static function (int $length) use (&$readBytes): string {
            $readBytes += $length;
            return str_repeat('x', $length);
        });
        $client = self::createStub(GuzzleClientInterface::class);
        $client->method('send')->willReturn(new Response(200, [], $stream));
        $recorder = new FeedHttpRecorder($client, 10, 10);
        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('2 MiB');

        try {
            $recorder->sendRequest(new Request('GET', self::URL));
        } finally {
            self::assertSame(2 * 1024 * 1024 + 1, $readBytes);
            self::assertTrue($stream->eof());
        }
    }

    #[Test]
    public function recorderAppliesTheBudgetAcrossRedirectResponses(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(302, ['Location' => self::URL], str_repeat('x', 1024 * 1024)),
            new Response(200, [], str_repeat('x', 1024 * 1024 + 1)),
        ]))]);
        $recorder = new FeedHttpRecorder($client, 10, 10);
        $recorder->sendRequest(new Request('GET', self::URL));
        $this->expectException(RequestException::class);
        $recorder->sendRequest(new Request('GET', self::URL));
    }

    #[Test]
    public function localReplayPreservesSourceContextWithoutReadingTheFileAgain(): void
    {
        $factory = self::createStub(GuzzleClientFactory::class);
        $http = new HttpFactory();
        $fetcher = new \ErHaWeb\FeedDisplay\Service\FeedFetcher(new FeedRuntimeInitializer($factory, $http, $http), $factory);
        $path = tempnam(__DIR__ . '/../../../.Build/Web/typo3temp', 'feed-replay-');
        self::assertIsString($path);
        $this->testFilesToDelete[] = $path . '.moved';
        file_put_contents($path, '<?xml version="1.0" encoding="ISO-8859-1"?><rss version="2.0"><channel><title>Stra' . "\xDF" . 'e</title><description>Test</description><item><title>Item</title><link>article.html</link></item></channel></rss>');
        $live = $fetcher->fetch($path);
        rename($path, $path . '.moved');
        $restored = $fetcher->restore(\ErHaWeb\FeedDisplay\Storage\FeedDocument::decode($live->document->encode()));
        self::assertSame($this->getFeedValues($live->feed), $this->getFeedValues($restored));
    }

    /**
     * @return array<string, mixed>
     */
    private function getFeedValues(SimplePie $feed): array
    {
        return SimplePieDeprecationHandler::run(static fn(): array => [
            'title' => $feed->get_title(),
            'subscription' => $feed->subscribe_url(),
            'permanentSubscription' => $feed->subscribe_url(true),
            'base' => $feed->get_base(),
            'language' => $feed->get_language(),
            'self' => $feed->get_link(0, 'self'),
            'itemLink' => $feed->get_item(0)?->get_permalink(),
        ]);
    }
}
