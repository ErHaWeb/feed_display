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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Upgrades;

use ErHaWeb\FeedDisplay\Upgrades\FeedUrlFlexFormMigration;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedUrlFlexFormMigrationTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::makeInstance(CacheManager::class)->setCacheConfigurations([
            'runtime' => [
                'frontend' => VariableFrontend::class,
                'backend' => TransientMemoryBackend::class,
            ],
        ]);
    }

    #[Test]
    public function migrationMovesLegacyFeedUrlToFeedIrreReference(): void
    {
        $subject = new FeedUrlFlexFormMigration($this->createMock(ConnectionPool::class));
        $reflectionMethod = new \ReflectionMethod(FeedUrlFlexFormMigration::class, 'migrateFlexFormXml');

        $migratedFlexForm = (string)$reflectionMethod->invoke(
            $subject,
            $this->createLegacyFlexFormXml('https://example.com/feed.xml'),
            42,
        );

        self::assertStringContainsString('index="settings.feeds"', $migratedFlexForm);
        self::assertStringContainsString('>42<', $migratedFlexForm);
        self::assertStringNotContainsString('settings.feedUrl', $migratedFlexForm);
        self::assertStringNotContainsString('https://example.com/feed.xml', $migratedFlexForm);
    }

    private function createLegacyFlexFormXml(string $feedUrl): string
    {
        return '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
            . '<T3FlexForms><data><sheet index="general"><language index="lDEF">'
            . '<field index="settings.feedUrl"><value index="vDEF">'
            . htmlspecialchars($feedUrl, ENT_XML1 | ENT_QUOTES)
            . '</value></field></language></sheet></data></T3FlexForms>';
    }
}
