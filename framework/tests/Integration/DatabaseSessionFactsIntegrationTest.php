<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;

/** Session diagnostics use the requested connection and follow its replacement handle. */
final class DatabaseSessionFactsIntegrationTest extends FrameworkIntegrationTestCase
{
    private const int EXTRA_INDEX = 1;

    /** Standalone MariaDB exposes its member, connection id and default wsrep session values. */
    public function testFactsMatchTheCurrentServerSession(): void
    {
        $facts = Database::sessionFacts(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $row = Database::sql('SELECT @@hostname AS member, CONNECTION_ID() AS connection_id')->firstRow();

        self::assertNotNull($row);
        self::assertSame($row['member'], $facts->member);
        self::assertSame((int)$row['connection_id'], $facts->connectionId);
        self::assertSame(0, $facts->wsrepSyncWait);
        self::assertFalse($facts->wsrepDirtyReads);
    }

    /** An explicit index remains separate from the active index and reports its new session after connect. */
    public function testFactsFollowTheRequestedIndexAfterConnect(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(
            index: self::EXTRA_INDEX,
            host: $primary->host,
            user: $primary->user,
            password: $primary->password,
            database: $primary->database,
            port: $primary->port,
            socket: $primary->socket,
            charset: $primary->charset,
        );
        Database::connect(self::EXTRA_INDEX);
        $before = Database::sessionFacts(self::EXTRA_INDEX);
        self::assertNotSame(
            Database::sessionFacts(DatabaseConnectionDefaults::PRIMARY_INDEX)->connectionId,
            $before->connectionId,
        );

        Database::connect(self::EXTRA_INDEX);
        $after = Database::sessionFacts(self::EXTRA_INDEX);
        $row = Database::sql(
            'SELECT @@hostname AS member, CONNECTION_ID() AS connection_id',
            index: self::EXTRA_INDEX,
        )->firstRow();

        self::assertNotNull($row);
        self::assertNotSame($before->connectionId, $after->connectionId);
        self::assertSame($row['member'], $after->member);
        self::assertSame((int)$row['connection_id'], $after->connectionId);
        self::assertSame(0, $after->wsrepSyncWait);
        self::assertFalse($after->wsrepDirtyReads);
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }
}
