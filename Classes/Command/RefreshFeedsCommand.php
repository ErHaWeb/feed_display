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

use ErHaWeb\FeedDisplay\Service\ConfiguredFeedUrls;
use ErHaWeb\FeedDisplay\Service\FeedRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'feeddisplay:refresh', description: 'Refresh configured and stored feed sources')]
final class RefreshFeedsCommand extends Command
{
    public function __construct(private readonly ConfiguredFeedUrls $urls, private readonly FeedRefresher $refresher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('url', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Additional feed source (repeatable; commas remain part of the URL)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $additional */
        $additional = $input->getOption('url');
        try {
            $urls = $this->urls->collect($additional);
        } catch (\Throwable $exception) {
            $io->error(OutputFormatter::escape('Could not collect feed sources: ' . $exception->getMessage()));
            return self::FAILURE;
        }
        if ($urls === []) {
            $io->success('No feed sources found. Explicit FlexForm URLs, stored sources and --url are considered.');
            return self::SUCCESS;
        }
        $status = self::SUCCESS;
        foreach ($urls as $url) {
            $result = $this->refresher->refresh($url, true);
            $io->writeln(OutputFormatter::escape($result->status . ': ' . $url));
            if ($result->error !== null) {
                $io->error(OutputFormatter::escape($result->error->getMessage()));
                $status = self::FAILURE;
            }
        }
        return $status;
    }
}
