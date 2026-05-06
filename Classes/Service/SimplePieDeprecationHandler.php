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

/**
 * Keeps confirmed SimplePie vendor deprecations from becoming TYPO3 errors.
 *
 * New suppressions should be added only after verifying that SimplePie can
 * continue parsing correctly once the vendor deprecation is ignored.
 *
 * @internal
 */
final class SimplePieDeprecationHandler
{
    private mixed $previousHandler = null;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        return (new self())->runCallback($callback);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function runCallback(callable $callback): mixed
    {
        // Scope the custom handler to one SimplePie call and restore TYPO3's
        // global error handling immediately afterwards.
        $this->previousHandler = set_error_handler($this->handleError(...));

        try {
            return $callback();
        } finally {
            restore_error_handler();
            $this->previousHandler = null;
        }
    }

    private function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if ($this->isKnownSimplePieDeprecation($severity, $message, $file)) {
            return true;
        }

        $previousHandler = $this->previousHandler;
        if (is_callable($previousHandler)) {
            return (bool)$previousHandler($severity, $message, $file, $line);
        }

        return false;
    }

    private function isKnownSimplePieDeprecation(int $severity, string $message, string $file): bool
    {
        if ($severity !== E_DEPRECATED && $severity !== E_USER_DEPRECATED) {
            return false;
        }

        // Do not suppress by package path alone: each vendor deprecation must
        // be explicitly known and harmless for feed parsing.
        return $this->isKnownIriNullOffsetDeprecation($message, $file);
    }

    private function isKnownIriNullOffsetDeprecation(string $message, string $file): bool
    {
        return str_contains($message, 'Using null as an array offset is deprecated')
            && str_ends_with(str_replace('\\', '/', $file), '/simplepie/simplepie/src/IRI.php');
    }
}
