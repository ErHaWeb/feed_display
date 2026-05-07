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

use ErHaWeb\FeedDisplay\Upgrades\FeedDisplayCTypeMigration;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class FeedDisplayCTypeMigrationTest extends UnitTestCase
{
    #[Test]
    public function migrationMapsLegacyListTypeToDedicatedCType(): void
    {
        $subject = new FeedDisplayCTypeMigration($this->createMock(ConnectionPool::class));
        $reflectionMethod = new \ReflectionMethod(
            FeedDisplayCTypeMigration::class,
            'getListTypeToCTypeMapping',
        );

        self::assertSame(
            [
                'feeddisplay_pi1' => 'feeddisplay_pi1',
            ],
            $reflectionMethod->invoke($subject),
        );
    }
}
