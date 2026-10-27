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

namespace ErHaWeb\FeedDisplay\Storage;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Collects the feed URLs set in the FlexForm of feed_display content elements.
 *
 * Feed URLs that only come from TypoScript cannot be resolved outside of a
 * page request; they are known once a page using them has been rendered.
 *
 * @internal
 */
class ConfiguredFeedUrls
{
    private const CONTENT_TYPE = 'feeddisplay_pi1';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<string>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Hidden or scheduled elements may become visible, so only deleted ones are skipped.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $flexForms = $queryBuilder
            ->select('pi_flexform')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter(self::CONTENT_TYPE)))
            ->executeQuery()
            ->fetchFirstColumn();

        $urls = [];
        foreach ($flexForms as $flexForm) {
            $url = $this->getFeedUrl((string)$flexForm);
            if ($url !== '') {
                $urls[$url] = $url;
            }
        }

        return array_values($urls);
    }

    private function getFeedUrl(string $flexForm): string
    {
        if ($flexForm === '') {
            return '';
        }

        $flexFormData = GeneralUtility::xml2array($flexForm);
        if (!is_array($flexFormData) || !is_array($flexFormData['data'] ?? null)) {
            return '';
        }

        foreach ($flexFormData['data'] as $sheet) {
            $value = $sheet['lDEF']['settings.feedUrl']['vDEF'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return stripslashes(trim($value));
            }
        }

        return '';
    }
}
