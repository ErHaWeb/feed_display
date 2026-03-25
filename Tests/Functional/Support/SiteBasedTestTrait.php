<?php

declare(strict_types=1);

namespace ErHaWeb\FeedDisplay\Tests\Functional\Support;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @phpstan-type LanguagePreset array{id: int, title: string, locale: string, iso?: string, websiteTitle?: string}
 * @phpstan-type DefaultLanguageConfiguration array{languageId: int, title: string, navigationTitle: string, websiteTitle: string, base: string, locale: string, flag: string}
 * @phpstan-type LanguageConfiguration array{languageId: int, title: string, navigationTitle: string, websiteTitle: string, base: string, locale: string, flag: string, fallbackType: string, fallbacks?: string}
 * @phpstan-type SiteLanguageConfiguration DefaultLanguageConfiguration|LanguageConfiguration
 * @phpstan-type SiteConfigurationData array<string, mixed>
 * @phpstan-type CspConfiguration array<string, mixed>
 */
trait SiteBasedTestTrait
{
    /**
     * @param SiteConfigurationData $site
     * @param list<SiteLanguageConfiguration> $languages
     * @param list<array<string, mixed>> $errorHandling
     * @param list<string> $dependencies
     * @param CspConfiguration|null $csp
     */
    protected function writeSiteConfiguration(
        string $identifier,
        array $site = [],
        array $languages = [],
        array $errorHandling = [],
        array $dependencies = [],
        ?array $csp = null,
    ): void {
        $configuration = $site;
        if ($languages !== []) {
            $configuration['languages'] = $languages;
        }
        if ($errorHandling !== []) {
            $configuration['errorHandling'] = $errorHandling;
        }
        if ($dependencies !== []) {
            $configuration['dependencies'] = $dependencies;
        }

        GeneralUtility::rmdir($this->instancePath . '/typo3conf/sites/' . $identifier, true);
        try {
            $siteWriterClass = 'TYPO3\\CMS\\Core\\Configuration\\SiteWriter';
            $writerService = (class_exists($siteWriterClass) && $this->has($siteWriterClass))
                ? $siteWriterClass
                : SiteConfiguration::class;

            $writer = $this->get($writerService);

            if (!is_object($writer) || !is_callable([$writer, 'write'])) {
                throw new \LogicException(
                    sprintf('Site writer for "%s" does not provide write().', $identifier),
                    1742210103,
                );
            }

            $write = \Closure::fromCallable([$writer, 'write']);
            $write($identifier, $configuration);
        } catch (SiteConfigurationWriteException $exception) {
            throw new \LogicException(
                sprintf('Writing site configuration "%s" failed.', $identifier),
                1742210101,
                $exception,
            );
        }

        if ($csp !== null) {
            GeneralUtility::writeFile(
                $this->instancePath . '/typo3conf/sites/' . $identifier . '/csp.yaml',
                Yaml::dump($csp, 99, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP),
                true,
            );
        }
    }

    /**
     * @return array{rootPageId: int, base: string}
     */
    protected function buildSiteConfiguration(int $rootPageId, string $base = ''): array
    {
        return [
            'rootPageId' => $rootPageId,
            'base' => $base,
        ];
    }

    /**
     * @return DefaultLanguageConfiguration
     */
    protected function buildDefaultLanguageConfiguration(string $identifier, string $base): array
    {
        $configuration = $this->buildLanguageConfiguration($identifier, $base);
        $configuration['flag'] = 'global';
        unset($configuration['fallbackType'], $configuration['fallbacks']);

        return $configuration;
    }

    /**
     * @param list<string> $fallbackIdentifiers
     * @return LanguageConfiguration
     */
    protected function buildLanguageConfiguration(
        string $identifier,
        string $base,
        array $fallbackIdentifiers = [],
        ?string $fallbackType = null,
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
            'fallbackType' => $fallbackType ?? ($fallbackIdentifiers === [] ? 'strict' : 'fallback'),
        ];

        if ($fallbackIdentifiers !== []) {
            $fallbackIds = array_map(
                fn (string $fallbackIdentifier): int => $this->resolveLanguagePreset($fallbackIdentifier)['id'],
                $fallbackIdentifiers,
            );
            $configuration['fallbackType'] = $fallbackType ?? 'fallback';
            $configuration['fallbacks'] = implode(',', $fallbackIds);
        }

        return $configuration;
    }

    /**
     * @return LanguagePreset
     */
    protected function resolveLanguagePreset(string $identifier): array
    {
        if (!isset(static::LANGUAGE_PRESETS[$identifier])) {
            throw new \LogicException(
                sprintf('Undefined preset identifier "%s".', $identifier),
                1742210102,
            );
        }

        return static::LANGUAGE_PRESETS[$identifier];
    }
}
