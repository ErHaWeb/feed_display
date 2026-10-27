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

use ErHaWeb\FeedDisplay\Command\RefreshFeedsCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerFetchModeTest extends AbstractFeedFrontendTestCase
{
    #[Test]
    public function firstRenderingFetchesAFeedThatWasNeverFetched(): void
    {
        $feedUrl = $this->writeFeedFixture('Bootstrapped feed', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['fetchMode' => 'scheduler']);

        self::assertStringContainsString('Bootstrapped feed', $this->requestPage());
    }

    #[Test]
    public function renderingNeverRefetchesAndTheRefreshCommandUpdatesTheFeed(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['fetchMode' => 'scheduler', 'cacheDuration' => 0]);
        $this->requestPage();

        $this->writeFeedFixture('Changed title', ['First item']);
        self::assertStringContainsString('Initial title', $this->requestPage());

        (new CommandTester($this->get(RefreshFeedsCommand::class)))->execute([]);
        self::assertStringContainsString('Changed title', $this->requestPage());
    }

    #[Test]
    public function flexFormCanSwitchASingleElementToSchedulerMode(): void
    {
        $feedUrl = $this->writeFeedFixture('Initial title', ['First item']);
        $this->initializeFrontendRootPage($feedUrl, ['cacheDuration' => 0]);
        $this->setFlexFormValues([
            'advanced' => [
                'settings.fetchMode' => 'scheduler',
            ],
        ]);
        $this->requestPage();

        $this->writeFeedFixture('Changed title', ['First item']);

        self::assertStringContainsString('Initial title', $this->requestPage());
    }
}
