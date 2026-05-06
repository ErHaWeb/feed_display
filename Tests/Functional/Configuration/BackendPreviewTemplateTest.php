<?php

declare(strict_types=1);

namespace ErHaWeb\FeedDisplay\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Domain\FlexFormFieldValues;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendPreviewTemplateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'erhaweb/feed-display',
    ];

    #[Test]
    public function backendPreviewTemplateRendersTypo313FlexFormValues(): void
    {
        $html = $this->renderBackendPreview([
            'pi_flexform_transformed' => [
                'settings' => [
                    'feedUrl' => 'https://example.com/feed.xml',
                    'maxFeedCount' => '5',
                    'dateFormat' => 'Y-m-d',
                    'getFields' => [
                        'items' => 'id,title,link',
                    ],
                ],
            ],
        ]);

        self::assertExpectedFlexFormOutput($html);
    }

    #[Test]
    public function backendPreviewTemplateRendersTypo314FlexFormValues(): void
    {
        $html = $this->renderBackendPreview([
            'record' => [
                'pi_flexform' => self::createFlexFormFieldValues([
                    'general' => [
                        'settings' => [
                            'feedUrl' => 'https://example.com/feed.xml',
                            'maxFeedCount' => '5',
                        ],
                    ],
                    'advanced' => [
                        'settings' => [
                            'dateFormat' => 'Y-m-d',
                        ],
                    ],
                    'getFields' => [
                        'settings' => [
                            'getFields' => [
                                'items' => ['id', 'title', 'link'],
                            ],
                        ],
                    ],
                ]),
            ],
        ]);

        self::assertExpectedFlexFormOutput($html);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderBackendPreview(array $variables): string
    {
        $templatePath = GeneralUtility::getFileAbsFileName(
            'EXT:feed_display/Resources/Private/Templates/Backend/Preview.html'
        );

        $view = $this->get(ViewFactoryInterface::class)
            ->create(new ViewFactoryData(templatePathAndFilename: $templatePath));

        foreach ($variables as $name => $value) {
            $view->assign($name, $value);
        }

        return $view->render();
    }

    /**
     * @param array<string, mixed> $sheets
     */
    private static function createFlexFormFieldValues(array $sheets): object
    {
        if (
            class_exists(FlexFormFieldValues::class)
            && method_exists(FlexFormFieldValues::class, 'getSheets')
        ) {
            return new FlexFormFieldValues($sheets);
        }

        return new class ($sheets) {
            /**
             * @param array<string, mixed> $sheets
             */
            public function __construct(
                private readonly array $sheets,
            ) {}

            /**
             * @return array<string, mixed>
             */
            public function getSheets(): array
            {
                return $this->sheets;
            }
        };
    }

    private static function assertExpectedFlexFormOutput(string $html): void
    {
        self::assertStringContainsString('https://example.com/feed.xml', $html);
        self::assertStringContainsString('5', $html);
        self::assertStringContainsString('Y-m-d', $html);
        self::assertStringNotContainsString('id,title,link', $html);
    }
}
