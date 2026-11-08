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

use SimplePie\SimplePie;

/**
 * @internal Captures and restores the input to SimplePie's XML parser, without repeating discovery.
 * @phpstan-type SourceResponse array{uri: string, base: string, status: int, headers: array<string, list<string>>, body: string}
 * @phpstan-type SourceSnapshot array{response: SourceResponse, mime: string}
 */
final class FeedParser extends SimplePie
{
    /** @param SourceSnapshot|null $snapshot */
    public function __construct(private ?array $snapshot = null)
    {
        parent::__construct();
        if ($snapshot !== null) {
            $this->feed_url = $snapshot['response']['uri'];
            $this->permanent_url = $snapshot['response']['base'];
            $this->enable_cache(false);
        }
    }

    /** @return SourceSnapshot */
    public function getSnapshot(): array
    {
        return $this->snapshot ?? throw new \LogicException('No successfully fetched parser input.');
    }

    /**
     * @param \SimplePie\Cache\Base|\SimplePie\Cache\DataCache|false $cache
     * @return array{array<string, string>, string}|bool
     */
    protected function fetch_data(&$cache)
    {
        if ($this->snapshot === null) {
            $result = parent::fetch_data($cache);
            if (is_array($result)) {
                $this->snapshot = [
                    'response' => [
                        'uri' => $this->feed_url,
                        'base' => $this->permanent_url ?? $this->feed_url,
                        'status' => $this->status_code,
                        'headers' => array_map(static fn(string $value): array => [$value], $result[0]),
                        'body' => (string)$this->get_raw_data(),
                    ],
                    'mime' => $result[1],
                ];
            }
            return $result;
        }

        $response = $this->snapshot['response'];
        $this->raw_data = $response['body'];
        $this->feed_url = $response['uri'];
        $this->permanent_url = $response['base'];
        $this->status_code = $response['status'];
        return [array_map(static fn(array $values): string => implode(', ', $values), $response['headers']), $this->snapshot['mime']];
    }
}
