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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** The tag keeps this command out of the Scheduler on TYPO3 13 and 14. */
#[AutoconfigureTag('console.command', [
    'command' => 'feeddisplay:remove',
    'description' => 'Remove explicitly selected stored feed sources',
    'schedulable' => false,
])]
final class RemoveFeedSourcesCommand extends Command
{
    public function __construct(private readonly FeedRefresher $refresher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('url', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exact source to remove (required, repeatable)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $arguments */
        $arguments = $input->getOption('url');
        $urls = array_values(array_unique(array_map(static fn(string $url): string => trim(stripslashes($url)), $arguments)));
        if ($urls === [] || in_array('', $urls, true)) {
            $io->error('Specify each source to remove with --url.');
            return self::INVALID;
        }
        $status = self::SUCCESS;
        foreach ($urls as $url) {
            $result = $this->refresher->remove($url);
            $io->writeln(OutputFormatter::escape(($result->status === 'updated' ? 'removed' : $result->status) . ': ' . $url));
            if ($result->error !== null) {
                $io->error(OutputFormatter::escape($result->error->getMessage()));
            }
            if ($result->status !== 'updated') {
                $status = self::FAILURE;
            }
        }
        return $status;
    }
}
