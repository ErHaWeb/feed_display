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

namespace ErHaWeb\FeedDisplay\Tests\Unit\Storage;

use ErHaWeb\FeedDisplay\Storage\FeedDocument;
use ErHaWeb\FeedDisplay\Tests\Fixtures\StoredDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedDocumentTest extends UnitTestCase
{
    #[Test]
    public function arbitraryBytesRoundTripWithoutSerializedObjects(): void
    {
        $document = StoredDocument::create("\xff\x00Stra\xDFe");
        self::assertSame($document->payload, FeedDocument::decode($document->encode())->payload);
    }

    #[Test]
    #[DataProvider('invalidDocuments')]
    public function malformedDocumentsAreRejected(mixed $payload): void
    {
        $this->expectException(\Exception::class);
        FeedDocument::decode(serialize($payload));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDocuments(): iterable
    {
        yield 'incomplete snapshot' => [['version' => 2, 'kind' => 'snapshot', 'snapshot' => []]];
        $response = ['uri' => 'https://example.test', 'base' => 'https://example.test', 'status' => 200, 'headers' => [], 'body' => 'XML'];
        foreach ([['base' => null], ['status' => 700], ['status' => '200'], ['headers' => ['Bad Header' => ['x']]], ['body' => new \stdClass()]] as $invalid) {
            yield 'snapshot ' . json_encode($invalid) => [['version' => 2, 'kind' => 'snapshot', 'snapshot' => [
                'response' => array_replace($response, $invalid), 'mime' => 'text/xml',
            ]]];
        }
        yield 'serialized object' => [(object)['version' => 1]];
        yield 'missing format' => [[]];
        yield 'unsupported format' => [['version' => 1, 'kind' => 'http', 'steps' => []]];
    }
}
