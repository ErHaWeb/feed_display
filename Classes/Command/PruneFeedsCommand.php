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
    name: 'feeddisplay:prune',
    description: 'Removes stored feeds that have not been rendered for a while.',
)]
final class PruneFeedsCommand extends Command
{
    public function __construct(
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
                'Deletes stored feeds that have not been rendered within the given number of days.'
                . ' Feeds set in feed_display content elements are always kept.'
            )
            ->addOption(
                'max-age-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete feeds not rendered within this number of days',
                '30'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $maxAgeDays = max(1, (int)$input->getOption('max-age-days'));

        $deleted = $this->storage->deleteUnusedSince(
            $now - $maxAgeDays * 86400,
            $this->configuredFeedUrls->findAll()
        );

        (new SymfonyStyle($input, $output))->success(sprintf('%d stored feeds removed.', $deleted));

        return Command::SUCCESS;
    }
}
