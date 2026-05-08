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

namespace ErHaWeb\FeedDisplay\Upgrades;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
// TYPO3 v13/v14 compatibility: Use the EXT:install upgrade wizard namespaces.
// For TYPO3 v14/v15-only compatibility, switch to TYPO3\CMS\Core\*.
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

#[UpgradeWizard('feedDisplayFeedUrlFlexFormMigration')]
final class FeedUrlFlexFormMigration implements UpgradeWizardInterface
{
    private const TABLE_NAME = 'tt_content';
    private const FEED_TABLE = 'tx_feeddisplay_domain_model_feed';
    private const PLUGIN_CTYPE = 'feeddisplay_pi1';
    private const LEGACY_FIELD_NAME = 'settings.feedUrl';
    private const TARGET_FIELD_NAME = 'settings.feeds';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Migrate Feed Display FlexForm feed URLs';
    }

    public function getDescription(): string
    {
        return 'Migrates legacy Feed Display FlexForm values from settings.feedUrl '
            . 'to IRRE feed records referenced by settings.feeds.';
    }

    public function executeUpdate(): bool
    {
        $contentConnection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
        $feedConnection = $this->connectionPool->getConnectionForTable(self::FEED_TABLE);

        foreach ($this->getRecordsToMigrate() as $record) {
            $flexFormXml = (string)$record['pi_flexform'];
            $legacyFeedUrl = $this->getLegacyFeedUrlFromXml($flexFormXml);
            $feedUid = null;

            if ($legacyFeedUrl !== null && trim($legacyFeedUrl) !== '' && !$this->hasTargetFeedReferencesXml($flexFormXml)) {
                $time = time();
                $feedConnection->insert(
                    self::FEED_TABLE,
                    [
                        'pid' => (int)$record['pid'],
                        'tt_content' => (int)$record['uid'],
                        'url' => $legacyFeedUrl,
                        'sorting' => 1,
                        'hidden' => 0,
                        'tstamp' => $time,
                        'crdate' => $time,
                    ]
                );
                $feedUid = (int)$feedConnection->lastInsertId();
            }

            $contentConnection->update(
                self::TABLE_NAME,
                [
                    'pi_flexform' => $this->migrateFlexFormXml($flexFormXml, $feedUid),
                ],
                [
                    'uid' => (int)$record['uid'],
                ]
            );
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        return $this->getRecordsToMigrate() !== [];
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * @return list<array{uid: int|string, pid: int|string, pi_flexform: mixed}>
     */
    private function getRecordsToMigrate(): array
    {
        $recordsToMigrate = [];
        foreach ($this->getCandidateRecords() as $record) {
            $flexFormXml = (string)($record['pi_flexform'] ?? '');
            if ($flexFormXml !== '' && $this->migrateFlexFormXml($flexFormXml) !== $flexFormXml) {
                $recordsToMigrate[] = $record;
            }
        }

        return $recordsToMigrate;
    }

    /**
     * @return list<array{uid: int|string, pid: int|string, pi_flexform: mixed}>
     */
    private function getCandidateRecords(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder
            ->select('uid', 'pid', 'pi_flexform')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->like(
                    'pi_flexform',
                    $queryBuilder->createNamedParameter('%' . self::LEGACY_FIELD_NAME . '%')
                ),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq(
                        'CType',
                        $queryBuilder->createNamedParameter(self::PLUGIN_CTYPE)
                    ),
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq(
                            'CType',
                            $queryBuilder->createNamedParameter('list')
                        ),
                        $queryBuilder->expr()->eq(
                            'list_type',
                            $queryBuilder->createNamedParameter(self::PLUGIN_CTYPE)
                        )
                    )
                )
            );

        /** @var list<array{uid: int|string, pid: int|string, pi_flexform: mixed}> $records */
        $records = $queryBuilder->executeQuery()->fetchAllAssociative();

        return $records;
    }

    private function migrateFlexFormXml(string $flexFormXml, ?int $feedUid = null): string
    {
        $flexFormArray = GeneralUtility::xml2array($flexFormXml);
        if (!is_array($flexFormArray)) {
            return $flexFormXml;
        }

        $legacyFeedUrl = $this->getLegacyFeedUrl($flexFormArray);
        if ($legacyFeedUrl === null) {
            return $flexFormXml;
        }

        if (!$this->hasTargetFeedReferences($flexFormArray) && $feedUid !== null) {
            $flexFormArray['data']['general']['lDEF'][self::TARGET_FIELD_NAME]['vDEF'] = (string)$feedUid;
        }
        $this->removeLegacyFeedUrl($flexFormArray);

        return $this->flexArrayToXml($flexFormArray);
    }

    private function getLegacyFeedUrlFromXml(string $flexFormXml): ?string
    {
        $flexFormArray = GeneralUtility::xml2array($flexFormXml);
        if (!is_array($flexFormArray)) {
            return null;
        }

        return $this->getLegacyFeedUrl($flexFormArray);
    }

    private function hasTargetFeedReferencesXml(string $flexFormXml): bool
    {
        $flexFormArray = GeneralUtility::xml2array($flexFormXml);

        return is_array($flexFormArray) && $this->hasTargetFeedReferences($flexFormArray);
    }

    /**
     * @param array<string, mixed> $flexFormArray
     */
    private function getLegacyFeedUrl(array $flexFormArray): ?string
    {
        foreach (($flexFormArray['data'] ?? []) as $sheetData) {
            if (!is_array($sheetData)) {
                continue;
            }
            $fieldData = $sheetData['lDEF'][self::LEGACY_FIELD_NAME] ?? null;
            if (is_array($fieldData) && array_key_exists('vDEF', $fieldData)) {
                return (string)$fieldData['vDEF'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $flexFormArray
     */
    private function hasTargetFeedReferences(array $flexFormArray): bool
    {
        foreach (($flexFormArray['data'] ?? []) as $sheetData) {
            if (!is_array($sheetData)) {
                continue;
            }
            $fieldData = $sheetData['lDEF'][self::TARGET_FIELD_NAME] ?? null;
            if (is_array($fieldData) && trim((string)($fieldData['vDEF'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $flexFormArray
     */
    private function removeLegacyFeedUrl(array &$flexFormArray): void
    {
        if (!isset($flexFormArray['data']) || !is_array($flexFormArray['data'])) {
            return;
        }

        foreach ($flexFormArray['data'] as &$sheetData) {
            if (is_array($sheetData)) {
                unset($sheetData['lDEF'][self::LEGACY_FIELD_NAME]);
            }
        }
        unset($sheetData);
    }

    /**
     * @param array<string, mixed> $flexFormArray
     */
    private function flexArrayToXml(array $flexFormArray): string
    {
        $options = [
            'parentTagMap' => [
                'data' => 'sheet',
                'sheet' => 'language',
                'language' => 'field',
                'el' => 'field',
                'field' => 'value',
                'field:el' => 'el',
                'el:_IS_NUM' => 'section',
                'section' => 'itemType',
            ],
            'disableTypeAttrib' => 2,
        ];

        return '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>' . "\n"
            . GeneralUtility::array2xml($flexFormArray, '', 0, 'T3FlexForms', 4, $options);
    }
}
