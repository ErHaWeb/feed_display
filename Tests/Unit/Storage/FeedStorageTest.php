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

use Doctrine\DBAL\Result;
use ErHaWeb\FeedDisplay\Storage\FeedStorage;
use ErHaWeb\FeedDisplay\Tests\Fixtures\StoredDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FeedStorageTest extends UnitTestCase
{
    #[Test]
    public function binaryLobStreamsAreDecodedLikeStrings(): void
    {
        $document = StoredDocument::create("Binary \x00\xFF\xDF");
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $document->encode());
        rewind($stream);
        try {
            $row = ['url' => 'source', 'response_data' => $stream, 'revision' => $document->revision(), 'fetched_at' => 1000, 'last_attempt_at' => 1000, 'last_error' => '', 'invalidation_pending' => 0];
            $result = self::createStub(Result::class);
            $result->method('fetchAssociative')->willReturn($row);
            $connection = self::createStub(Connection::class);
            $connection->method('select')->willReturn($result);
            $pool = self::createStub(ConnectionPool::class);
            $pool->method('getConnectionForTable')->willReturn($connection);
            self::assertSame($document->payload, (new FeedStorage($pool, new NullLogger()))->find('source')?->document?->payload);
        } finally {
            fclose($stream);
        }
    }
    #[Test]
    public function lazyDocumentReadsSupportLobStreamsAndMatchTheRequestedRevision(): void
    {
        $document = StoredDocument::create("Binary \x00\xFF\xDF");
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $document->encode());
        rewind($stream);
        try {
            $result = self::createStub(Result::class);
            $result->method('fetchAssociative')->willReturn(['response_data' => $stream]);
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('select')->with(['response_data'], FeedStorage::TABLE, [
                'url_hash' => FeedStorage::hashUrl('source'), 'revision' => $document->revision(),
            ])->willReturn($result);
            $pool = self::createStub(ConnectionPool::class);
            $pool->method('getConnectionForTable')->willReturn($connection);
            self::assertSame($document->payload, (new FeedStorage($pool, new NullLogger()))->loadDocument('source', $document->revision())?->payload);
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    #[DataProvider('storedRevisions')]
    public function revisionLookupDoesNotLoadTheDocument(string|false $value, ?string $expected): void
    {
        $hash = FeedStorage::hashUrl('https://example.test/feed');
        $result = self::createStub(Result::class);
        $result->method('fetchOne')->willReturn($value);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('select')->with(['revision'], FeedStorage::TABLE, ['url_hash' => $hash])->willReturn($result);
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);
        self::assertSame($expected, (new FeedStorage($pool, new NullLogger()))->findRevisionByHash($hash));
    }

    /** @return iterable<string, array{string|false, ?string}> */
    public static function storedRevisions(): iterable
    {
        yield 'successful source' => [str_repeat('a', 64), str_repeat('a', 64)];
        yield 'missing source' => [false, null];
        yield 'failed first attempt' => ['', ''];
    }

}
