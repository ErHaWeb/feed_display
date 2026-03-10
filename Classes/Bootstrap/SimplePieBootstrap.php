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

namespace ErHaWeb\FeedDisplay\Bootstrap;

use SimplePie\SimplePie;

/**
 * @internal
 */
class SimplePieBootstrap
{
    public function __construct(
        private readonly string $extensionPath,
    ) {}

    public function ensureLibraryIsLoaded(): void
    {
        if ($this->isSimplePieAvailable()) {
            return;
        }

        $this->requireAutoloadFile(
            'phar://' . $this->extensionPath . 'Libraries/simplepie-simplepie.phar/vendor/autoload.php'
        );
    }

    protected function isSimplePieAvailable(): bool
    {
        return class_exists(SimplePie::class);
    }

    protected function requireAutoloadFile(string $autoloaderPath): void
    {
        require_once $autoloaderPath;
    }
}
