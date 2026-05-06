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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Controller;

use ErHaWeb\FeedDisplay\Controller\FeedController;
use ErHaWeb\FeedDisplay\Service\FeedDataService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedControllerTest extends UnitTestCase
{
    #[Test]
    public function displayActionReturnsResponseWithoutTouchingCacheIfSettingsAreEmpty(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);

        $cache->expects(self::never())->method('get');
        $feedDataService->expects(self::never())->method('buildData');

        $subject = new TestableFeedController($cache, $feedDataService);
        $subject->setSettings([]);
        $subject->setView(new RecordingView());

        $subject->displayAction();
    }

    #[Test]
    public function displayActionBypassesCacheIfCacheDurationIsZero(): void
    {
        $settings = [
            'cacheDuration' => 0,
        ];
        $data = [
            'settings' => $settings,
            'items' => [
                ['title' => 'First item'],
            ],
        ];
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $view = new RecordingView();

        $cache->expects(self::once())->method('get')->with('feeddisplay')->willReturn([
            'settings' => ['cacheDuration' => 600],
        ]);
        $cache->expects(self::once())->method('remove')->with('feeddisplay');
        $cache->expects(self::never())->method('set');
        $feedDataService->expects(self::once())->method('buildData')->with($settings)->willReturn($data);

        $subject = new TestableFeedController($cache, $feedDataService);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($data, $view->assigned['data']);
    }

    #[Test]
    public function displayActionCachesBuiltDataOnCacheMiss(): void
    {
        $settings = [
            'cacheDuration' => 600,
        ];
        $data = [
            'settings' => $settings,
            'items' => [
                ['title' => 'Cached item'],
            ],
        ];
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $view = new RecordingView();

        $cache->expects(self::once())->method('get')->with('feeddisplay')->willReturn(false);
        $cache->expects(self::once())->method('set')->with('feeddisplay', $data, [], 600);
        $cache->expects(self::never())->method('remove');
        $feedDataService->expects(self::once())->method('buildData')->with($settings)->willReturn($data);

        $subject = new TestableFeedController($cache, $feedDataService);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($data, $view->assigned['data']);
    }

    #[Test]
    public function displayActionReusesCachedDataWhenSettingsMatch(): void
    {
        $settings = [
            'cacheDuration' => 600,
        ];
        $cachedData = [
            'settings' => $settings,
            'items' => [
                ['title' => 'Cached item'],
            ],
        ];
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $view = new RecordingView();

        $cache->expects(self::once())->method('get')->with('feeddisplay')->willReturn($cachedData);
        $cache->expects(self::never())->method('set');
        $cache->expects(self::never())->method('remove');
        $feedDataService->expects(self::never())->method('buildData');

        $subject = new TestableFeedController($cache, $feedDataService);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($cachedData, $view->assigned['data']);
    }

    #[Test]
    public function displayActionRebuildsCacheWhenCachedSettingsDoNotMatch(): void
    {
        $settings = [
            'cacheDuration' => 600,
        ];
        $cachedData = [
            'settings' => [
                'cacheDuration' => 60,
            ],
            'items' => [
                ['title' => 'Outdated item'],
            ],
        ];
        $rebuiltData = [
            'settings' => $settings,
            'items' => [
                ['title' => 'Fresh item'],
            ],
        ];
        $cache = $this->createMock(FrontendInterface::class);
        $feedDataService = $this->createMock(FeedDataService::class);
        $view = new RecordingView();

        $cache->expects(self::once())->method('get')->with('feeddisplay')->willReturn($cachedData);
        $cache->expects(self::once())->method('set')->with('feeddisplay', $rebuiltData, [], 600);
        $cache->expects(self::never())->method('remove');
        $feedDataService->expects(self::once())->method('buildData')->with($settings)->willReturn($rebuiltData);

        $subject = new TestableFeedController($cache, $feedDataService);
        $subject->setSettings($settings);
        $subject->setView($view);
        $subject->displayAction();

        self::assertSame($rebuiltData, $view->assigned['data']);
    }
}

final class TestableFeedController extends FeedController
{
    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function setView(ViewInterface $view): void
    {
        $this->view = $view;
    }

    protected function htmlResponse(?string $html = null): ResponseInterface
    {
        return new HtmlResponse($html ?? '');
    }
}

final class RecordingView implements ViewInterface
{
    /** @var array<string, mixed> */
    public array $assigned = [];

    public function assign(string $key, mixed $value): self
    {
        $this->assigned[$key] = $value;
        return $this;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function assignMultiple(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->assigned[$key] = $value;
        }
        return $this;
    }

    public function render(string $templateFileName = ''): string
    {
        return '';
    }

    /**
     * @param array<array-key, mixed> $variables
     */
    public function renderSection(string $sectionName, array $variables = [], bool $ignoreUnknown = false): string
    {
        return '';
    }

    /**
     * @param array<array-key, mixed> $variables
     */
    public function renderPartial(string $partialName, string $sectionName, array $variables, bool $ignoreUnknown = false): string
    {
        return '';
    }
}
