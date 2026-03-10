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

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;

final class FeedFrontendTest extends AbstractFeedFrontendTestCase
{
    #[Test]
    #[IgnoreDeprecations]
    public function frontendRequestRendersConfiguredFeedData(): void
    {
        $feedUrl = $this->writeFeedFixture('Feed display test feed', ['First item', 'Second item']);
        $this->initializeFrontendRootPage($feedUrl);

        $body = $this->requestPage();

        self::assertStringContainsString('Feed display test feed', $body);
        self::assertStringContainsString('First item', $body);
        self::assertStringContainsString('Second item', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function flexFormOverridesTypoScriptAndChangedSettingsInvalidateTheFeedCache(): void
    {
        $feedUrl = $this->writeFeedFixture('Feed display test feed', ['First item', 'Second item']);
        $this->initializeFrontendRootPage($feedUrl, ['maxFeedCount' => 2]);

        $this->setFlexFormValues([
            'general' => [
                'settings.maxFeedCount' => 1,
            ],
        ]);

        $body = $this->requestPage();
        self::assertSame(1, substr_count($body, 'class="item-title"'));

        $this->setFlexFormValues([
            'general' => [
                'settings.maxFeedCount' => 2,
            ],
        ]);

        $body = $this->requestPage();
        self::assertSame(2, substr_count($body, 'class="item-title"'));
        self::assertStringContainsString('First item', $body);
        self::assertStringContainsString('Second item', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function cachedFeedDataIsReusedWhenFeedContentChanges(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['cacheDuration' => 600]);

        $firstBody = $this->requestPage();
        self::assertStringContainsString('Initial title', $firstBody);

        $this->writeFeedFixture('Changed title', ['First item']);
        $secondBody = $this->requestPage();
        self::assertStringContainsString('Initial title', $secondBody);
        self::assertStringNotContainsString('Changed title', $secondBody);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function zeroCacheDurationBypassesTheExtensionCache(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['cacheDuration' => 0]);

        $firstBody = $this->requestPage();
        self::assertStringContainsString('Initial title', $firstBody);

        $this->writeFeedFixture('Changed title', ['First item']);
        $secondBody = $this->requestPage();
        self::assertStringContainsString('Changed title', $secondBody);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function emptyFeedUrlDisplaysConfiguredErrorMessage(): void
    {
        $this->initializeFrontendRootPage('', ['errorMessage' => 'Feed unavailable for test']);

        $body = $this->requestPage();

        self::assertStringContainsString('Feed unavailable for test', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function feedImageGetsPublishedToTemporaryAssets(): void
    {
        $feedUrl = $this->writeFeedFixture('Feed with image', ['First item'], true);
        $this->initializeFrontendRootPage($feedUrl);

        $body = $this->requestPage();

        self::assertStringContainsString('typo3temp/assets/images/', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function invalidFeedDisplaysTheDefaultErrorMessage(): void
    {
        $this->initializeFrontendRootPage($this->instancePath . '/fileadmin/missing-feed.xml', [
            'getFields.feed' => '',
        ]);

        $body = $this->requestPage();

        self::assertStringContainsString('Sorry, the feed could not be fetched.', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function invalidFeedRendersConfiguredHtmlErrorMessageWithoutEscaping(): void
    {
        $this->initializeFrontendRootPage(
            $this->instancePath . '/fileadmin/missing-feed.xml',
            [
                'getFields.feed' => '',
                'errorMessage' => '<p><strong>Feed unavailable</strong></p>',
            ]
        );

        $body = $this->requestPage();

        self::assertStringContainsString('<strong>Feed unavailable</strong>', $body);
        self::assertStringNotContainsString('&lt;strong&gt;Feed unavailable&lt;/strong&gt;', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function feedWithoutItemsDisplaysTheTranslatedNoItemsMessage(): void
    {
        $feedUrl = $this->writeFeedXmlFixture(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Empty feed</title><link>https://example.com/</link><description>Test feed</description></channel></rss>'
        );
        $this->initializeFrontendRootPage($feedUrl);

        $body = $this->requestPage();

        self::assertStringContainsString('Sorry, no items could be fetched.', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function frontendRequestAppliesFormattingAndLinkSettingsToRenderedItems(): void
    {
        $feedUrl = $this->writeFeedXmlFixture(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Formatting feed</title><link>https://example.com/</link><description>Test feed</description><item><title>ABCDEFGHIJKLMNO</title><link>https://example.com/item-1</link><guid>item-1</guid><description><![CDATA[<p><strong>Bold</strong> text and more</p>]]></description><pubDate>Tue, 10 Mar 2026 10:01:00 +0000</pubDate></item></channel></rss>',
            'formatting-feed.xml'
        );
        $this->initializeFrontendRootPage($feedUrl, [
            'getFields.items' => 'id,title,content,date|U,link',
            'maxHeaderLength' => 10,
            'maxContentLength' => 15,
            'stripTags' => 1,
            'dateFormat' => 'Y-m-d',
            'linkTarget' => '_self',
        ]);

        $body = $this->requestPage();

        self::assertStringContainsString('ABCDEFG', $body);
        self::assertStringNotContainsString('ABCDEFGHIJKLMNO', $body);
        self::assertStringContainsString('Bold text', $body);
        self::assertStringNotContainsString('<strong>Bold</strong>', $body);
        self::assertStringNotContainsString('and more', $body);
        self::assertStringContainsString('2026-03-10', $body);
        self::assertStringContainsString('target="_self"', $body);
        self::assertStringContainsString('id="item-item-1"', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function frontendRequestCanRenderHtmlContentWithoutStrippingTags(): void
    {
        $feedUrl = $this->writeFeedXmlFixture(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>HTML content feed</title><link>https://example.com/</link><description>Test feed</description><item><title>HTML item</title><link>https://example.com/item-1</link><guid>item-1</guid><description><![CDATA[<p><strong>Bold</strong> text</p>]]></description></item></channel></rss>',
            'html-feed.xml'
        );
        $this->initializeFrontendRootPage($feedUrl, [
            'getFields.items' => 'title,content,link',
            'stripTags' => 0,
            'maxContentLength' => 0,
        ]);

        $body = $this->requestPage();

        self::assertStringContainsString('<strong>Bold</strong>', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function missingPluginSettingsDisplayTheStaticTypoScriptHint(): void
    {
        $feedUrl = $this->writeFeedFixture('Feed display test feed', ['First item']);
        $this->initializeFrontendRootPage($feedUrl);
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, 'plugin.tx_feeddisplay_pi1.settings >');

        $body = $this->requestPage();

        self::assertStringContainsString(
            'Feed Display: Please include static TypoScript [EXT:feed_display/Configuration/TypoScript/]',
            $body
        );
    }

    #[Test]
    #[IgnoreDeprecations]
    public function frontendRequestAppliesConfiguredImageDimensionsToFeedIconAndLogo(): void
    {
        $feedImagePath = $this->instancePath . '/fileadmin/rectangular-feed-image.svg';
        file_put_contents(
            $feedImagePath,
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 120 60"><rect width="120" height="60" fill="#333"/></svg>'
        );
        $feedUrl = $this->writeFeedXmlFixture(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Image dimensions feed</title><link>https://example.com/</link><description>Test feed</description><image><url>file://' . $feedImagePath . '</url><title>Feed image</title><link>https://example.com/logo</link><width>120</width><height>60</height></image><item><title>First item</title><link>https://example.com/item-1</link><guid>item-1</guid></item></channel></rss>',
            'image-dimensions-feed.xml'
        );
        $this->initializeFrontendRootPage($feedUrl, [
            'getFields.feed' => 'title,subscribe_url,image_url,image_link,image_title,image_width,image_height',
            'feedIconMaxWidth' => 18,
            'feedIconMaxHeight' => 18,
            'logoMaxWidth' => 90,
            'logoMaxHeight' => 40,
        ]);

        $body = $this->requestPage();

        self::assertStringContainsString('width="18" height="18"', $body);
        self::assertStringContainsString('title="Feed image"', $body);
        self::assertStringContainsString('width="80" height="40"', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function frontendRequestRendersSingularMetadataBranchesFromCachedFeedData(): void
    {
        $feedUrl = $this->writeFeedFixture('Cached metadata feed', ['Initial item']);
        $this->initializeFrontendRootPage($feedUrl, [
            'stripTags' => 0,
            'dateFormat' => 'Y-m-d',
        ]);

        $this->requestPage();
        $cachedData = $this->getRequiredCachedFeedData();

        $cachedData['feed'] = [
            'title' => 'Cached singular feed',
            'description' => '<p>Cached singular description</p>',
            'categories' => [
                ['term' => 'Cached feed category'],
            ],
            'authors' => [
                ['name' => 'Cached feed author'],
            ],
            'contributors' => [
                ['name' => 'Cached feed contributor'],
            ],
            'links' => [
                'https://example.com/feed-main',
                'https://example.com/feed-secondary',
            ],
            'copyright' => 'Cached feed copyright',
        ];
        $cachedData['items'] = [[
            'title' => 'Cached singular item',
            'content' => '<p>Cached singular content</p>',
            'categories' => [
                ['term' => 'Cached item category'],
            ],
            'authors' => [
                ['name' => 'Cached item author'],
            ],
            'contributors' => [
                ['name' => 'Cached item contributor'],
            ],
            'copyright' => 'Cached item copyright',
            'date' => '2026-03-10 10:00:00',
            'updatedDate' => '2026-03-11 10:00:00',
            'links' => [
                'https://example.com/item-link',
            ],
            'enclosures' => [
                ['link' => 'https://example.com/enclosure-one.mp3'],
            ],
            'latitude' => 48,
            'longitude' => 11,
            'source' => 42,
        ]];
        $this->setCachedFeedData($cachedData);

        $body = $this->requestPage();

        self::assertStringContainsString('Cached singular description', $body);
        self::assertStringContainsString('Cached feed copyright', $body);
        self::assertStringContainsString('Cached feed category', $body);
        self::assertStringContainsString('Cached feed author', $body);
        self::assertStringContainsString('Cached feed contributor', $body);
        self::assertStringContainsString('https://example.com/feed-secondary', $body);
        self::assertStringContainsString('<p>Cached singular content</p>', $body);
        self::assertStringContainsString('Cached item category', $body);
        self::assertStringContainsString('Cached item author', $body);
        self::assertStringContainsString('Cached item contributor', $body);
        self::assertStringContainsString('Cached item copyright', $body);
        self::assertStringContainsString('https://example.com/item-link', $body);
        self::assertStringContainsString('https://example.com/enclosure-one.mp3', $body);
        self::assertStringContainsString('2026-03-11', $body);
        self::assertStringContainsString('48.00', $body);
        self::assertStringContainsString('11.00', $body);
        self::assertStringContainsString('42.00', $body);
        self::assertRenderedLabelCount($body, 'Category', 2);
        self::assertRenderedLabelCount($body, 'Author', 2);
        self::assertRenderedLabelCount($body, 'Contributor', 2);
        self::assertRenderedLabelCount($body, 'Link', 2);
        self::assertRenderedLabelCount($body, 'Enclosure', 1);
        self::assertRenderedLabelCount($body, 'Categories', 0);
        self::assertRenderedLabelCount($body, 'Authors', 0);
        self::assertRenderedLabelCount($body, 'Contributors', 0);
        self::assertRenderedLabelCount($body, 'Links', 0);
        self::assertRenderedLabelCount($body, 'Enclosures', 0);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function frontendRequestRendersPluralMetadataBranchesFromCachedFeedData(): void
    {
        $feedUrl = $this->writeFeedFixture('Cached metadata feed', ['Initial item']);
        $this->initializeFrontendRootPage($feedUrl, [
            'stripTags' => 0,
            'dateFormat' => 'Y-m-d',
        ]);

        $this->requestPage();
        $cachedData = $this->getRequiredCachedFeedData();

        $cachedData['feed'] = [
            'title' => 'Cached plural feed',
            'description' => '<p>Cached plural description</p>',
            'categories' => [
                ['term' => 'Cached feed category one'],
                ['term' => 'Cached feed category two'],
            ],
            'authors' => [
                ['name' => 'Cached feed author one'],
                ['name' => 'Cached feed author two'],
            ],
            'contributors' => [
                ['name' => 'Cached feed contributor one'],
                ['name' => 'Cached feed contributor two'],
            ],
            'links' => [
                'https://example.com/feed-main',
                'https://example.com/feed-secondary',
                'https://example.com/feed-third',
            ],
        ];
        $cachedData['items'] = [[
            'title' => 'Cached plural item',
            'content' => '<p>Cached plural content</p>',
            'categories' => [
                ['term' => 'Cached item category one'],
                ['term' => 'Cached item category two'],
            ],
            'authors' => [
                ['name' => 'Cached item author one'],
                ['name' => 'Cached item author two'],
            ],
            'contributors' => [
                ['name' => 'Cached item contributor one'],
                ['name' => 'Cached item contributor two'],
            ],
            'date' => '2026-03-10 10:00:00',
            'updatedDate' => '2026-03-11 10:00:00',
            'links' => [
                'https://example.com/item-main',
                'https://example.com/item-secondary',
                'https://example.com/item-third',
            ],
            'enclosures' => [
                ['link' => 'https://example.com/item-enclosure-two.mp3'],
                ['link' => 'https://example.com/item-enclosure-three.mp3'],
            ],
        ]];
        $this->setCachedFeedData($cachedData);

        $body = $this->requestPage();

        self::assertStringContainsString('Cached plural description', $body);
        self::assertStringContainsString('Cached feed category one', $body);
        self::assertStringContainsString('Cached feed category two', $body);
        self::assertStringContainsString('Cached feed author one', $body);
        self::assertStringContainsString('Cached feed author two', $body);
        self::assertStringContainsString('Cached feed contributor one', $body);
        self::assertStringContainsString('Cached feed contributor two', $body);
        self::assertStringContainsString('https://example.com/feed-secondary', $body);
        self::assertStringContainsString('https://example.com/feed-third', $body);
        self::assertStringContainsString('<p>Cached plural content</p>', $body);
        self::assertStringContainsString('Cached item category one', $body);
        self::assertStringContainsString('Cached item category two', $body);
        self::assertStringContainsString('Cached item author one', $body);
        self::assertStringContainsString('Cached item author two', $body);
        self::assertStringContainsString('Cached item contributor one', $body);
        self::assertStringContainsString('Cached item contributor two', $body);
        self::assertStringContainsString('https://example.com/item-main', $body);
        self::assertStringContainsString('https://example.com/item-secondary', $body);
        self::assertStringContainsString('https://example.com/item-third', $body);
        self::assertStringContainsString('https://example.com/item-enclosure-two.mp3', $body);
        self::assertStringContainsString('https://example.com/item-enclosure-three.mp3', $body);
        self::assertRenderedLabelCount($body, 'Categories', 2);
        self::assertRenderedLabelCount($body, 'Authors', 2);
        self::assertRenderedLabelCount($body, 'Contributors', 2);
        self::assertRenderedLabelCount($body, 'Links', 2);
        self::assertRenderedLabelCount($body, 'Enclosures', 1);
        self::assertRenderedLabelCount($body, 'Category', 0);
        self::assertRenderedLabelCount($body, 'Author', 0);
        self::assertRenderedLabelCount($body, 'Contributor', 0);
        self::assertRenderedLabelCount($body, 'Link', 0);
        self::assertRenderedLabelCount($body, 'Enclosure', 0);
    }

    private static function assertRenderedLabelCount(string $body, string $label, int $expectedCount): void
    {
        preg_match_all('/<strong>\s*' . preg_quote($label, '/') . '\s*<\/strong>/', $body, $matches);
        self::assertCount($expectedCount, $matches[0]);
    }

    private function getRequiredCachedFeedData(): array
    {
        $cachedData = $this->getCachedFeedData();
        self::assertIsArray($cachedData);

        if (!is_array($cachedData)) {
            throw new \LogicException('Expected cached feed data to be available as array.', 5546276907);
        }

        return $cachedData;
    }
}
