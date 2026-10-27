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

use ErHaWeb\FeedDisplay\Service\FetchMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FetchModeTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{mixed, FetchMode}>
     */
    public static function settingValues(): iterable
    {
        yield 'live' => ['live', FetchMode::Live];
        yield 'scheduler' => ['scheduler', FetchMode::Scheduler];
        yield 'scheduler with whitespace' => [' scheduler ', FetchMode::Scheduler];
        yield 'empty' => ['', FetchMode::Live];
        yield 'unknown' => ['cron', FetchMode::Live];
        yield 'missing' => [null, FetchMode::Live];
    }

    #[Test]
    #[DataProvider('settingValues')]
    public function fromSettingFallsBackToLive(mixed $value, FetchMode $expected): void
    {
        self::assertSame($expected, FetchMode::fromSetting($value));
    }
}
