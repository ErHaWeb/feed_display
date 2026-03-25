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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Bootstrap;

use ErHaWeb\FeedDisplay\Bootstrap\SimplePieBootstrap;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SimplePieBootstrapTest extends UnitTestCase
{
    #[Test]
    public function ensureLibraryIsLoadedDoesNothingIfSimplePieIsAlreadyAvailable(): void
    {
        $subject = $this->getMockBuilder(SimplePieBootstrap::class)
            ->setConstructorArgs(['/var/www/feed_display/'])
            ->onlyMethods(['isSimplePieAvailable', 'requireAutoloadFile'])
            ->getMock();

        $subject->expects(self::once())->method('isSimplePieAvailable')->willReturn(true);
        $subject->expects(self::never())->method('requireAutoloadFile');

        $subject->ensureLibraryIsLoaded();
    }

    #[Test]
    public function ensureLibraryIsLoadedRequiresPharAutoloadWhenSimplePieIsMissing(): void
    {
        $subject = $this->getMockBuilder(SimplePieBootstrap::class)
            ->setConstructorArgs(['/var/www/feed_display/'])
            ->onlyMethods(['isSimplePieAvailable', 'requireAutoloadFile'])
            ->getMock();

        $subject->expects(self::once())->method('isSimplePieAvailable')->willReturn(false);
        $subject->expects(self::once())
            ->method('requireAutoloadFile')
            ->with('phar:///var/www/feed_display/Libraries/simplepie-simplepie.phar/vendor/autoload.php');

        $subject->ensureLibraryIsLoaded();
    }
}
