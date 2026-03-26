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

use ErHaWeb\FeedDisplay\Tests\Functional\Support\SiteBasedTestTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractFeedFrontendTestCase extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    protected const ROOT_PAGE_ID = 1;
    protected const CONTENT_ELEMENT_UID = 100;
    /** @var array<string, array{id: int, title: string, locale: string}> */
    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
    ];

    protected array $testExtensionsToLoad = [
        'erhaweb/feed-display',
    ];

    protected array $coreExtensionsToLoad = [
        'fluid_styled_content',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/feed_display/Resources/Public/Icons/Extension.svg' => 'fileadmin/feed-image.svg',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'encryptionKey' => '4408d27a916d51e624b69af3554f516dbab61037a9f7b9fd6f81b4d3bedeccb6',
        ],
        'FE' => [
            'cacheHash' => [
                'enforceValidation' => false,
            ],
            'debug' => false,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/Pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Database/TtContent.csv');
        $this->writeSiteConfiguration(
            'feed-display',
            $this->buildSiteConfiguration(self::ROOT_PAGE_ID, 'https://feed-display.test/'),
            [
                $this->buildDefaultLanguageConfiguration('EN', '/'),
            ]
        );
    }

    /**
     * @param array<string, scalar> $settings
     */
    protected function initializeFrontendRootPage(string $feedUrl, array $settings = []): void
    {
        $this->setUpFrontendRootPage(
            self::ROOT_PAGE_ID,
            [
                'constants' => [
                    'EXT:fluid_styled_content/Configuration/TypoScript/constants.typoscript',
                    'EXT:feed_display/Configuration/TypoScript/constants.typoscript',
                ],
                'setup' => [
                    'EXT:fluid_styled_content/Configuration/TypoScript/setup.typoscript',
                    'EXT:feed_display/Tests/Functional/Fixtures/TypoScript/Frontend.typoscript',
                ],
            ]
        );

        // Keep the functional fixtures focused on extension-owned fields and avoid
        // unrelated feed metadata such as favicon handling in the default TypoScript.
        $typoScriptLines = [
            'plugin.tx_feeddisplay_pi1.settings.feedUrl = ' . $feedUrl,
            'plugin.tx_feeddisplay_pi1.settings.getFields.feed = title,subscribe_url',
            'plugin.tx_feeddisplay_pi1.settings.getFields.items = title',
        ];
        foreach ($settings as $settingName => $settingValue) {
            $typoScriptLines[] = 'plugin.tx_feeddisplay_pi1.settings.' . $settingName . ' = ' . $settingValue;
        }
        $this->addTypoScriptToTemplateRecord(self::ROOT_PAGE_ID, implode(PHP_EOL, $typoScriptLines));
    }

    protected function requestPage(): string
    {
        return (string)$this->executeFrontendSubRequest(
            (new InternalRequest('https://feed-display.test/'))->withPageId(self::ROOT_PAGE_ID)
        )->getBody();
    }

    /**
     * @param list<string> $itemTitles
     */
    protected function writeFeedFixture(string $feedTitle, array $itemTitles, bool $includeImage = false): string
    {
        $imageUrl = '';
        if ($includeImage) {
            $imageUrl = 'file://' . $this->instancePath . '/fileadmin/feed-image.svg';
        }

        $items = '';
        foreach ($itemTitles as $index => $itemTitle) {
            $items .= sprintf(
                '<item><title>%1$s</title><link>https://example.com/item-%2$d</link><guid>item-%2$d</guid><description>%1$s description</description><pubDate>Tue, 10 Mar 2026 10:%2$02d:00 +0000</pubDate></item>',
                htmlspecialchars($itemTitle, ENT_XML1 | ENT_QUOTES),
                $index + 1
            );
        }

        $imageBlock = '';
        if ($imageUrl !== '') {
            $imageBlock = sprintf(
                '<image><url>%1$s</url><title>Feed Image</title><link>https://example.com/</link><width>120</width><height>60</height></image>',
                htmlspecialchars($imageUrl, ENT_XML1 | ENT_QUOTES)
            );
        }

        $feedXml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>%1$s</title><link>https://example.com/</link><description>Test feed</description>%2$s%3$s</channel></rss>',
            htmlspecialchars($feedTitle, ENT_XML1 | ENT_QUOTES),
            $imageBlock,
            $items
        );

        return $this->writeFeedXmlFixture($feedXml);
    }

    protected function writeFeedXmlFixture(string $feedXml, string $fileName = 'feed.xml'): string
    {
        $feedPath = $this->instancePath . '/fileadmin/' . $fileName;
        file_put_contents($feedPath, $feedXml);

        return $feedPath;
    }

    /**
     * @param array<string, array<string, scalar|null>> $sheetFieldValues
     */
    protected function setFlexFormValues(array $sheetFieldValues): void
    {
        if ($sheetFieldValues === []) {
            $flexFormXml = '';
        } else {
            $data = ['data' => []];
            foreach ($sheetFieldValues as $sheet => $fields) {
                foreach ($fields as $fieldName => $fieldValue) {
                    $data['data'][$sheet]['lDEF'][$fieldName] = [
                        'vDEF' => (string)$fieldValue,
                    ];
                }
            }
            $flexFormTools = $this->has(FlexFormTools::class)
                ? $this->get(FlexFormTools::class)
                : GeneralUtility::makeInstance(FlexFormTools::class);
            $flexFormXml = $flexFormTools->flexArray2Xml($data);
        }

        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update(
                'tt_content',
                ['pi_flexform' => $flexFormXml],
                ['uid' => self::CONTENT_ELEMENT_UID]
            );
    }

    /**
     * @return array<string, mixed>|false
     */
    protected function getCachedFeedData(): array|false
    {
        try {
            return $this->get(CacheManager::class)->getCache('feeddisplay')->get('feeddisplay');
        } catch (NoSuchCacheException $exception) {
            throw new \LogicException(
                'Cache "feeddisplay" must be available in frontend functional tests.',
                1741614942,
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function setCachedFeedData(array $data, int $lifetime = 600): void
    {
        try {
            $this->get(CacheManager::class)->getCache('feeddisplay')->set('feeddisplay', $data, [], $lifetime);
        } catch (NoSuchCacheException $exception) {
            throw new \LogicException(
                'Cache "feeddisplay" must be available in frontend functional tests.',
                1741614943,
                $exception
            );
        }
    }
}
