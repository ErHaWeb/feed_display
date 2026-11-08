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

use GuzzleHttp\Psr7\Response;

/**
 * @internal
 * @phpstan-import-type SourceResponse from \ErHaWeb\FeedDisplay\Service\FeedParser
 * @phpstan-import-type SourceSnapshot from \ErHaWeb\FeedDisplay\Service\FeedParser
 * @phpstan-type FeedPayload array{version: 2, kind: 'snapshot', snapshot: SourceSnapshot}
 */
final readonly class FeedDocument
{
    /** @param FeedPayload $payload */
    public function __construct(public array $payload) {}

    public function encode(): string
    {
        return serialize($this->payload);
    }

    public function revision(): string
    {
        return hash('sha256', $this->encode());
    }

    public static function decode(string $bytes): self
    {
        // Corrupt internal data is rejected below; never instantiate serialized objects.
        $value = @unserialize($bytes, ['allowed_classes' => false]);
        if (!is_array($value) || ($value['version'] ?? null) !== 2 || ($value['kind'] ?? null) !== 'snapshot') {
            throw new \UnexpectedValueException('Unsupported stored feed format.');
        }
        $snapshot = $value['snapshot'] ?? null;
        if (!is_array($snapshot) || !is_string($snapshot['mime'] ?? null)) {
            throw new \UnexpectedValueException('Invalid stored parser input.');
        }
        return new self(['version' => 2, 'kind' => 'snapshot', 'snapshot' => [
            'response' => self::decodeResponse($snapshot['response'] ?? null),
            'mime' => $snapshot['mime'],
        ]]);
    }

    /** @return SourceResponse */
    private static function decodeResponse(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['uri'] ?? null) || !is_string($value['base'] ?? null)
            || !is_int($value['status'] ?? null) || !is_string($value['body'] ?? null) || !self::validHeaders($value['headers'] ?? null)
        ) {
            throw new \UnexpectedValueException('Invalid stored source response.');
        }
        // Validate PSR-7 header and status semantics, not just PHP types.
        new Response($value['status'], $value['headers'], $value['body']);
        return [
            'uri' => $value['uri'],
            'base' => $value['base'],
            'status' => $value['status'],
            'headers' => $value['headers'],
            'body' => $value['body'],
        ];
    }

    /** @phpstan-assert-if-true array<string, list<string>> $headers */
    private static function validHeaders(mixed $headers): bool
    {
        if (!is_array($headers)) {
            return false;
        }
        foreach ($headers as $name => $values) {
            if (!is_string($name) || !is_array($values) || !array_is_list($values)) {
                return false;
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    return false;
                }
            }
        }
        return true;
    }
}
