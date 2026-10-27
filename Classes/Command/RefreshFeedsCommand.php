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

namespace ErHaWeb\FeedDisplay\Command;

use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use ErHaWeb\FeedDisplay\Service\RefreshResult;
use ErHaWeb\FeedDisplay\Storage\ConfiguredFeedUrls;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Context\Context;

#[AsCommand(
    name: 'feeddisplay:refresh',
    description: 'Fetches all feeds in use and stores their responses for rendering.',
)]
final class RefreshFeedsCommand extends Command
{
    public function __construct(
        private readonly FeedRefresher $refresher,
        private readonly FeedStorage $storage,
        private readonly ConfiguredFeedUrls $configuredFeedUrls,
        private readonly Context $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Fetches the feeds set in feed_display content elements and the feeds rendered within the last'
                . ' days. A failed fetch keeps the last successful response. Returns a failure exit code if any'
                . ' feed could not be fetched.'
            )
            ->addOption(
                'max-age-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Also fetch feeds rendered within this number of days',
                '30'
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional feed URL to fetch (can be repeated)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $maxAgeDays = max(0, (int)$input->getOption('max-age-days'));

        /** @var list<string> $additionalUrls */
        $additionalUrls = $input->getOption('url');
        $urls = array_values(array_unique(array_filter([
            ...$this->configuredFeedUrls->findAll(),
            ...$this->storage->findUrlsUsedSince($now - $maxAgeDays * 86400),
            ...array_map(trim(...), $additionalUrls),
        ], static fn(string $url): bool => $url !== '')));

        if ($urls === []) {
            $io->note('No feeds to fetch.');

            return Command::SUCCESS;
        }

        $failed = 0;
        $rows = [];
        foreach ($urls as $url) {
            $result = $this->refresher->refresh($url);
            $message = '';
            if ($result === RefreshResult::Failed) {
                $failed++;
                $message = (string)$this->storage->find($url)?->lastError;
            }
            $rows[] = [$result->value, $url, $message];
        }

        $io->table(['Result', 'Feed', 'Error'], $rows);

        if ($failed > 0) {
            $io->warning(sprintf('%d of %d feeds could not be fetched, their last successful responses are kept.', $failed, count($urls)));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d feeds fetched.', count($urls)));

        return Command::SUCCESS;
    }
}
