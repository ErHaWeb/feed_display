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

namespace ErHaWeb\FeedDisplay\Service;

use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/** @internal Explicit plugin URLs and previously encountered sources. */
final class ConfiguredFeedUrls
{
    public function __construct(private readonly ConnectionPool $connections, private readonly FeedStorage $storage, private readonly LoggerInterface $logger) {}

    /** @param list<string> $additional
     * @return list<string>
     */
    public function collect(array $additional): array
    {
        $urls = $this->storage->findUrls();
        $query = $this->connections->getQueryBuilderForTable('tt_content');
        $query->getRestrictions()->removeAll();
        $records = $query->select('*')->from('tt_content')->where(
            $query->expr()->eq('deleted', 0),
            $query->expr()->eq('t3ver_wsid', 0),
            $query->expr()->or(
                $query->expr()->eq('CType', $query->createNamedParameter('feeddisplay_pi1')),
                $query->expr()->eq('CType', $query->createNamedParameter('list')),
            ),
        )->executeQuery()->fetchAllAssociative();
        foreach ($records as $record) {
            if ($record['CType'] !== 'feeddisplay_pi1' && !($record['CType'] === 'list' && ($record['list_type'] ?? '') === 'feeddisplay_pi1')) {
                continue;
            }
            if (trim((string)($record['pi_flexform'] ?? '')) === '') {
                continue;
            }
            $form = GeneralUtility::xml2array((string)$record['pi_flexform']);
            if (!is_array($form)) {
                $this->logger->warning('Could not read feed FlexForm.', ['contentElementUid' => (int)$record['uid'], 'error' => $form]);
                continue;
            }
            $sheets = $form['data'] ?? [];
            if (!is_array($sheets)) {
                $this->logger->warning('Invalid feed FlexForm structure.', ['contentElementUid' => (int)$record['uid']]);
                continue;
            }
            foreach ($sheets as $sheet) {
                if (!is_array($sheet)) {
                    continue;
                }
                $url = $sheet['lDEF']['settings.feedUrl']['vDEF'] ?? null;
                if (is_string($url)) {
                    $urls[] = $url;
                }
            }
        }
        $urls = array_map(static fn(string $url): string => trim(stripslashes($url)), [...$urls, ...$additional]);
        return array_values(array_unique(array_filter($urls, static fn(string $url): bool => $url !== '')));
    }
}
