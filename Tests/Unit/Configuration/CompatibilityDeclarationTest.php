<?php

declare(strict_types=1);

namespace ErHaWeb\FeedDisplay\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompatibilityDeclarationTest extends TestCase
{
    private const EXPECTED_TYPO3_CONSTRAINT = '^12.4 || ^13.4';
    private const EXPECTED_TYPO3_EMCONF_RANGE = '12.4.0-13.4.99';
    private const EXPECTED_PHP_CONSTRAINT = '^8.1';
    private const EXPECTED_PHP_EMCONF_RANGE = '8.1.0-8.5.99';

    #[Test]
    public function composerManifestPreservesMixedTypo3Support(): void
    {
        $composer = $this->readComposerManifest();

        foreach ([
            'typo3/cms-backend',
            'typo3/cms-core',
            'typo3/cms-extbase',
            'typo3/cms-fluid',
            'typo3/cms-frontend',
        ] as $packageName) {
            self::assertSame(self::EXPECTED_TYPO3_CONSTRAINT, $composer['require'][$packageName]);
        }

        foreach ([
            'typo3/cms-fluid-styled-content',
            'typo3/cms-install',
        ] as $packageName) {
            self::assertSame(self::EXPECTED_TYPO3_CONSTRAINT, $composer['require-dev'][$packageName]);
        }

        self::assertSame('^7.0 || ^8.0', $composer['require-dev']['typo3/testing-framework']);
        self::assertSame(self::EXPECTED_PHP_CONSTRAINT, $composer['require']['php']);
    }

    #[Test]
    public function extEmconfStaysAlignedWithComposerCompatibilityDeclarations(): void
    {
        $emConf = $this->readExtEmconf();

        self::assertSame(self::EXPECTED_TYPO3_EMCONF_RANGE, $emConf['constraints']['depends']['typo3']);
        self::assertSame(self::EXPECTED_PHP_EMCONF_RANGE, $emConf['constraints']['depends']['php']);
    }

    /**
     * @return array{
     *     require: array<string, string>,
     *     require-dev: array<string, string>
     * }
     */
    private function readComposerManifest(): array
    {
        $composerJson = file_get_contents($this->extensionRootPath('composer.json'));
        self::assertNotFalse($composerJson);

        /** @var array{
         *     require: array<string, string>,
         *     require-dev: array<string, string>
         * } $composer
         */
        $composer = json_decode($composerJson, true, 512, JSON_THROW_ON_ERROR);

        return $composer;
    }

    /** @return array<string, mixed> */
    private function readExtEmconf(): array
    {
        $configuration = (static function (string $extEmconfPath): array {
            $_EXTKEY = 'feed_display';
            /** @var array<string, mixed> $EM_CONF */
            $EM_CONF = [];

            require $extEmconfPath;

            $emConfValue = $EM_CONF[$_EXTKEY] ?? null;

            return is_array($emConfValue) ? $emConfValue : [];
        })($this->extensionRootPath('ext_emconf.php'));

        self::assertNotEmpty($configuration);

        return $configuration;
    }

    private function extensionRootPath(string $relativePath): string
    {
        return dirname(__DIR__, 3) . '/' . ltrim($relativePath, '/');
    }
}
