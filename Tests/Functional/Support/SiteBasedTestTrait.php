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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Support;

use LogicException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Core-inspired site setup helpers for standalone extension functional tests.
 *
 * The original TYPO3\CMS\Core\Tests\Functional\SiteHandling\SiteBasedTestTrait
 * is not autoloadable in the isolated extension test environment, since
 * typo3/cms-core does not expose its test namespace as public API.
 *
 * This trait intentionally mirrors only the helpers that feed_display needs,
 * to stay close to core naming without carrying unrelated support code.
 */
trait SiteBasedTestTrait
{
    protected function writeSiteConfiguration(
        string $identifier,
        array $site = [],
        array $languages = [],
        array $errorHandling = [],
        array $dependencies = [],
        ?array $csp = null,
    ): void {
        $configuration = $site;
        if (!empty($languages)) {
            $configuration['languages'] = $languages;
        }
        if (!empty($errorHandling)) {
            $configuration['errorHandling'] = $errorHandling;
        }
        if (!empty($dependencies)) {
            $configuration['dependencies'] = $dependencies;
        }

        // Ensure no previous site configuration influences the test.
        GeneralUtility::rmdir($this->instancePath . '/typo3conf/sites/' . $identifier, true);
        try {
            if ($this->has(SiteWriter::class)) {
                $this->get(SiteWriter::class)->write($identifier, $configuration);
            } else {
                $this->get(SiteConfiguration::class)->write($identifier, $configuration);
            }
        } catch (SiteConfigurationWriteException $exception) {
            throw new LogicException(
                sprintf('Writing site configuration "%s" failed.', $identifier),
                1741614944,
                $exception
            );
        }

        if ($csp !== null) {
            GeneralUtility::writeFile(
                $this->instancePath . '/typo3conf/sites/' . $identifier . '/csp.yaml',
                Yaml::dump($csp, 99, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP),
                true
            );
        }
    }

    protected function buildSiteConfiguration(
        int $rootPageId,
        string $base = ''
    ): array {
        return [
            'rootPageId' => $rootPageId,
            'base' => $base,
        ];
    }

    protected function buildDefaultLanguageConfiguration(
        string $identifier,
        string $base
    ): array {
        $configuration = $this->buildLanguageConfiguration($identifier, $base);
        $configuration['flag'] = 'global';
        unset($configuration['fallbackType'], $configuration['fallbacks']);

        return $configuration;
    }

    protected function buildLanguageConfiguration(
        string $identifier,
        string $base,
        array $fallbackIdentifiers = [],
        ?string $fallbackType = null
    ): array {
        $preset = $this->resolveLanguagePreset($identifier);

        $configuration = [
            'languageId' => $preset['id'],
            'title' => $preset['title'],
            'navigationTitle' => $preset['title'],
            'websiteTitle' => $preset['websiteTitle'] ?? '',
            'base' => $base,
            'locale' => $preset['locale'],
            'flag' => $preset['iso'] ?? '',
            'fallbackType' => $fallbackType ?? (empty($fallbackIdentifiers) ? 'strict' : 'fallback'),
        ];

        if (!empty($fallbackIdentifiers)) {
            $fallbackIds = array_map(
                function (string $fallbackIdentifier): int {
                    $fallbackPreset = $this->resolveLanguagePreset($fallbackIdentifier);

                    return $fallbackPreset['id'];
                },
                $fallbackIdentifiers
            );
            $configuration['fallbackType'] = $fallbackType ?? 'fallback';
            $configuration['fallbacks'] = implode(',', $fallbackIds);
        }

        return $configuration;
    }

    protected function resolveLanguagePreset(string $identifier): array
    {
        if (!isset(static::LANGUAGE_PRESETS[$identifier])) {
            throw new LogicException(
                sprintf('Undefined preset identifier "%s"', $identifier),
                1741705202
            );
        }

        return static::LANGUAGE_PRESETS[$identifier];
    }
}
