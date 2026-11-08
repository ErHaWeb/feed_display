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

namespace ErHaWeb\FeedDisplay\Tests\Functional\Fixtures;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** Deterministic transport shared by the test and frontend sub-request bootstrap. */
final class FeedHttp
{
    /** @var list<string> */
    public static array $requests = [];
    /** @var array<string, Response> */
    public static array $responses = [];
    /** @var array<string, \Closure(): void> */
    public static array $beforeResponse = [];

    public static function middleware(callable $next): callable
    {
        return static function (RequestInterface $request, array $options) {
            $uri = (string)$request->getUri();
            self::$requests[] = $uri;
            $callback = self::$beforeResponse[$uri] ?? null;
            unset(self::$beforeResponse[$uri]);
            $callback?->__invoke();
            $response = self::$responses[$uri] ?? new Response(503, [], 'Unavailable');
            // The recorder closes consumed streams. Every request needs a fresh body.
            return Create::promiseFor(new Response($response->getStatusCode(), $response->getHeaders(), (string)$response->getBody()));
        };
    }

    public static function rss(string $title, string $extra = ''): Response
    {
        return new Response(200, ['Content-Type' => 'application/rss+xml'],
            '<rss version="2.0"><channel><title>' . $title . '</title><link>https://example.test/</link><description>Test</description>'
            . $extra . '<item><title>Item</title><link>https://example.test/item</link></item></channel></rss>');
    }

    public static function countRequests(string $url): int
    {
        return count(array_filter(self::$requests, static fn(string $request): bool => $request === $url));
    }
}
