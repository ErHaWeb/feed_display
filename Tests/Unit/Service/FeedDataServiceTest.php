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
use ErHaWeb\FeedDisplay\Service\FeedRuntimeInitializer;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use SimplePie\Item;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedDataServiceTest extends UnitTestCase
{
    #[Test]
    public function buildDataReturnsOnlySettingsIfFeedUrlIsMissing(): void
    {
        $feed = $this->getMockBuilder(SimplePie::class)->disableOriginalConstructor()->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies(false);

        $feed->expects(self::never())->method('set_feed_url');
        $feed->expects(self::never())->method('enable_cache');
        $feed->expects(self::never())->method('init');
        $eventDispatcher->expects(self::never())->method('dispatch');

        $settings = [
            'feedUrl' => '',
            'getFields' => [
                'feed' => 'title',
                'items' => 'title',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);

        self::assertSame(
            ['settings' => $settings],
            $subject->buildData($settings)
        );
    }

    #[Test]
    public function buildDataKeepsSimplePieDefaultTransportForLocalFeedPaths(): void
    {
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies(false);

        $feed->expects(self::once())->method('set_feed_url')->with('/var/www/public/fileadmin/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_items')->with(0, 0)->willReturn([]);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $settings = [
            'feedUrl' => '/var/www/public/fileadmin/feed.xml',
            'maxFeedCount' => 0,
            'getFields' => [
                'feed' => '',
                'items' => '',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);

        self::assertSame(['settings' => $settings], $subject->buildData($settings));
    }

    #[Test]
    public function buildDataMapsFeedAndItemFieldsAndDispatchesEvent(): void
    {
        $sourceImagePath = $this->createSourceImage('feed-data-service.svg');
        $expectedImageTarget = $this->getExpectedTemporaryImagePath($sourceImagePath);
        @unlink($expectedImageTarget);

        $item = new TestFeedItem();
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_title', 'subscribe_url', 'get_image_url', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_title')->willReturn('Feed title');
        $feed->expects(self::once())->method('subscribe_url')->willReturn('https://example.com/subscribe');
        $feed->expects(self::once())->method('get_image_url')->willReturn('file://' . $sourceImagePath);
        $feed->expects(self::once())->method('get_items')->with(0, 1)->willReturn([$item]);

        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (SingleFeedDataEvent $event): SingleFeedDataEvent {
                $itemProperties = $event->getItemProperties();
                $itemProperties['custom'] = 'changed by event';
                $event->setItemProperties($itemProperties);
                return $event;
            }
        );

        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
            'maxFeedCount' => 1,
            'getFields' => [
                'feed' => 'title,subscribe_url,image_url',
                'items' => 'title,date|U',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);
        $data = $subject->buildData($settings);

        self::assertSame('Feed title', $data['feed']['title']);
        self::assertSame('https://example.com/subscribe', $data['feed']['subscribeUrl']);
        self::assertSame('file://' . $sourceImagePath, $data['feed']['imageUrl']);
        self::assertSame('typo3temp/assets/images/' . basename($expectedImageTarget), $data['feed']['image']);
        self::assertSame('First item', $data['items'][0]['title']);
        self::assertSame(1700000000, $data['items'][0]['date']);
        self::assertSame('changed by event', $data['items'][0]['custom']);
        self::assertFileExists($expectedImageTarget);
    }

    #[Test]
    public function buildDataSupportsGetterCallsWithThreeAndFourFieldParts(): void
    {
        $item = new TestFeedItem();
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_items')->with(0, 1)->willReturn([$item]);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnArgument(0);

        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
            'maxFeedCount' => 1,
            'getFields' => [
                'feed' => '',
                'items' => 'custom_three|foo|bar,custom_four|one|two|three',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);
        $data = $subject->buildData($settings);

        self::assertSame('foo|bar', $data['items'][0]['customThree']);
        self::assertSame('one|two|three', $data['items'][0]['customFour']);
    }

    #[Test]
    public function buildDataDoesNotOverwriteAnAlreadyGeneratedTemporaryImage(): void
    {
        $sourceImagePath = $this->createSourceImage('feed-data-service-existing.svg');
        $expectedImageTarget = $this->getExpectedTemporaryImagePath($sourceImagePath);
        file_put_contents($expectedImageTarget, 'existing image content');

        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_image_url', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_image_url')->willReturn('file://' . $sourceImagePath);
        $feed->expects(self::once())->method('get_items')->with(0, 0)->willReturn([]);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
            'maxFeedCount' => 0,
            'getFields' => [
                'feed' => 'image_url',
                'items' => '',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);
        $data = $subject->buildData($settings);

        self::assertSame('typo3temp/assets/images/' . basename($expectedImageTarget), $data['feed']['image']);
        self::assertSame('existing image content', (string)file_get_contents($expectedImageTarget));
    }

    #[Test]
    public function buildDataStripsSlashesFromConfiguredFeedUrlBeforeInitializingSimplePie(): void
    {
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_items')->with(0, 0)->willReturn([]);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $settings = [
            'feedUrl' => 'https:\\/\\/example.com\\/feed.xml',
            'maxFeedCount' => 0,
            'getFields' => [
                'feed' => '',
                'items' => '',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);

        self::assertSame(['settings' => $settings], $subject->buildData($settings));
    }

    #[Test]
    public function buildDataReturnsNullForUnknownGettersAndUnsupportedArgumentCounts(): void
    {
        $item = new TestFeedItem();
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_items')->with(0, 1)->willReturn([$item]);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnArgument(0);

        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
            'maxFeedCount' => 1,
            'getFields' => [
                'feed' => '',
                'items' => 'missing_getter,custom_five|one|two|three|four',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);
        $data = $subject->buildData($settings);

        self::assertArrayHasKey('missingGetter', $data['items'][0]);
        self::assertNull($data['items'][0]['missingGetter']);
        self::assertArrayHasKey('customFive', $data['items'][0]);
        self::assertNull($data['items'][0]['customFive']);
    }

    #[Test]
    public function buildDataReturnsNullIfFeedImageUrlCannotBeResolvedToATemporaryFile(): void
    {
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_image_url', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_image_url')->willReturn('file:///path/to/non-existing.svg');
        $feed->expects(self::once())->method('get_items')->with(0, 0)->willReturn([]);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
            'maxFeedCount' => 0,
            'getFields' => [
                'feed' => 'image_url',
                'items' => '',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);
        $data = $subject->buildData($settings);

        self::assertSame('file:///path/to/non-existing.svg', $data['feed']['imageUrl']);
        self::assertNull($data['feed']['image']);
    }

    #[Test]
    public function buildDataReturnsNullIfFeedImageUrlHasNoFilenameOrExtension(): void
    {
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_image_url', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::once())->method('get_image_url')->willReturn('https://example.com/images/');
        $feed->expects(self::once())->method('get_items')->with(0, 0)->willReturn([]);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
            'maxFeedCount' => 0,
            'getFields' => [
                'feed' => 'image_url',
                'items' => '',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);
        $data = $subject->buildData($settings);

        self::assertSame('https://example.com/images/', $data['feed']['imageUrl']);
        self::assertNull($data['feed']['image']);
    }

    #[Test]
    public function buildDataReturnsSerializableDataForObjectRichFieldsAndEventInjectedClosures(): void
    {
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set_feed_url', 'enable_cache', 'init', 'get_items'])
            ->getMock();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        [$guzzleClientFactory, $requestFactory, $uriFactory] = $this->createHttpClientDependencies();
        $item = new TestFeedItem($this->createRelatedFeedMock());

        $feed->expects(self::once())->method('set_feed_url')->with('https://example.com/feed.xml');
        $feed->expects(self::once())->method('enable_cache')->with(false);
        $feed->expects(self::once())->method('init');
        $feed->expects(self::exactly(2))->method('get_items')->willReturnCallback(
            static function (int $start, int $end) use ($item): array {
                self::assertSame(0, $start);
                self::assertContains($end, [0, 1]);
                return [$item];
            }
        );

        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (SingleFeedDataEvent $event): SingleFeedDataEvent {
                $itemProperties = $event->getItemProperties();
                $itemProperties['callback'] = static fn (): string => 'not serializable';
                $event->setItemProperties($itemProperties);
                return $event;
            }
        );

        $settings = [
            'feedUrl' => 'https://example.com/feed.xml',
            'maxFeedCount' => 1,
            'getFields' => [
                'feed' => 'items',
                'items' => 'feed,title',
            ],
        ];

        $subject = $this->createSubject($feed, $eventDispatcher, $guzzleClientFactory, $requestFactory, $uriFactory);
        $data = $subject->buildData($settings);

        self::assertSame('First item', $data['feed']['items'][0]['title']);
        self::assertSame('Related feed', $data['items'][0]['feed']['title']);
        self::assertNull($data['items'][0]['callback']);
        self::assertNotSame('', serialize($data));
    }

    private function createSourceImage(string $fileName): string
    {
        $sourceImagePath = Environment::getPublicPath() . '/typo3temp/var/tests/' . $fileName;
        if (!is_dir(dirname($sourceImagePath))) {
            mkdir(dirname($sourceImagePath), 0777, true);
        }
        file_put_contents($sourceImagePath, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"></svg>');
        return $sourceImagePath;
    }

    private function getExpectedTemporaryImagePath(string $sourceImagePath): string
    {
        $temporaryFileName = md5(pathinfo($sourceImagePath, PATHINFO_FILENAME)) . '.' . pathinfo($sourceImagePath, PATHINFO_EXTENSION);
        $targetPath = Environment::getPublicPath() . '/typo3temp/assets/images/' . $temporaryFileName;
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }
        return $targetPath;
    }

    private function createSubject(
        SimplePie $feed,
        EventDispatcherInterface $eventDispatcher,
        GuzzleClientFactory $guzzleClientFactory,
        RequestFactoryInterface $requestFactory,
        UriFactoryInterface $uriFactory,
    ): FeedDataService {
        $feedRuntimeInitializer = new FeedRuntimeInitializer($guzzleClientFactory, $requestFactory, $uriFactory);

        return new FeedDataService($feed, $eventDispatcher, $feedRuntimeInitializer);
    }

    /**
     * @return array{0: GuzzleClientFactory, 1: RequestFactoryInterface, 2: UriFactoryInterface}
     */
    private function createHttpClientDependencies(bool $expectUsage = true): array
    {
        $guzzleClient = new Client();
        $guzzleClientFactory = $this->createMock(GuzzleClientFactory::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $uriFactory = $this->createMock(UriFactoryInterface::class);

        $guzzleClientFactory->expects($expectUsage ? self::once() : self::never())
            ->method('getClient')
            ->willReturn($guzzleClient);

        return [$guzzleClientFactory, $requestFactory, $uriFactory];
    }

    private function createRelatedFeedMock(): SimplePie
    {
        $feed = $this->getMockBuilder(SimplePie::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'get_all_discovered_feeds',
                'get_author',
                'get_authors',
                'get_base',
                'get_contributor',
                'get_contributors',
                'get_copyright',
                'get_description',
                'get_encoding',
                'get_favicon',
                'get_image_height',
                'get_image_link',
                'get_image_title',
                'get_image_url',
                'get_image_width',
                'get_item_quantity',
                'get_items',
                'get_language',
                'get_latitude',
                'get_link',
                'get_links',
                'get_longitude',
                'get_permalink',
                'get_title',
                'get_type',
                'subscribe_url',
            ])
            ->getMock();

        $feed->method('get_all_discovered_feeds')->willReturn([]);
        $feed->method('get_author')->willReturn(null);
        $feed->method('get_authors')->willReturn([]);
        $feed->method('get_base')->willReturn('');
        $feed->method('get_contributor')->willReturn(null);
        $feed->method('get_contributors')->willReturn([]);
        $feed->method('get_copyright')->willReturn('');
        $feed->method('get_description')->willReturn('');
        $feed->method('get_encoding')->willReturn('');
        $feed->method('get_favicon')->willReturn('');
        $feed->method('get_image_height')->willReturn(0);
        $feed->method('get_image_link')->willReturn('');
        $feed->method('get_image_title')->willReturn('');
        $feed->method('get_image_url')->willReturn('');
        $feed->method('get_image_width')->willReturn(0);
        $feed->method('get_item_quantity')->willReturn(0);
        $feed->method('get_items')->willReturn([]);
        $feed->method('get_language')->willReturn('');
        $feed->method('get_latitude')->willReturn(null);
        $feed->method('get_link')->willReturn('');
        $feed->method('get_links')->willReturn([]);
        $feed->method('get_longitude')->willReturn(null);
        $feed->method('get_permalink')->willReturn('');
        $feed->method('get_title')->willReturn('Related feed');
        $feed->method('get_type')->willReturn('');
        $feed->method('subscribe_url')->willReturn('');

        return $feed;
    }
}

final class TestFeedItem extends Item
{
    public function __construct(?SimplePie $feed = null)
    {
        parent::__construct($feed ?? new SimplePie(), []);
    }

    public function get_title(): string
    {
        return 'First item';
    }

    /**
     * @return ($date_format is 'U' ? int|null : string|null)
     */
    public function get_date(string $date_format = '')
    {
        if ($date_format === '__missing__') {
            return null;
        }

        return $date_format === 'U' ? 1700000000 : 'Tue, 14 Nov 2023 22:13:20 +0000';
    }

    /** @noinspection PhpUnused */
    public function get_custom_three(string $first, string $second): string
    {
        return $first . '|' . $second;
    }

    /** @noinspection PhpUnused */
    public function get_custom_four(string $first, string $second, string $third): string
    {
        return $first . '|' . $second . '|' . $third;
    }

    /** @noinspection PhpUnused */
    public function get_custom_five(string $first, string $second, string $third, string $fourth): string
    {
        return $first . '|' . $second . '|' . $third . '|' . $fourth;
    }
}
