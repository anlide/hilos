<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\EnvConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseMarker;
use Hilos\Database\DatabaseMarkerRow;
use Hilos\Database\DatabaseSql;
use Hilos\Database\Migration;
use Hilos\Hilos;

/**
 * The database marker against the live test database (HIL-1206).
 *
 * Nodes starting together cannot be raced inside one PHP process; that race is run by the cluster
 * stand, which starts five nodes at once on an empty database and converges only if all of them
 * read one marker. Here the pieces it is decided by are pinned: the first read writes the marker,
 * every later one - by this node or another - reads the same row, and the restore half puts a row
 * back or takes it away. A pause the marker did not need fails the case rather than sleeping.
 */
final class DatabaseMarkerIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Node id the marker is written under. */
    private const string NODE = 'marker-test-node';

    /** Node id of a node that wrote the marker before this one started. */
    private const string OTHER_NODE = 'another-node';

    /** Marker another node left in the row. */
    private const string OTHER_MARKER = '0123456789abcdef0123456789abcdef';

    /** When the other node wrote it. */
    private const string OTHER_WRITTEN_AT = '2026-01-02 03:04:05';

    /**
     * Connection index no other case migrates on, so the framework tables are probed on it afresh:
     * the flag that skips the probe is per connection and lives as long as the process.
     */
    private const int FRESH_INDEX = 6;

    protected function setUp(): void
    {
        parent::setUp();

        Migration::initialize();
        DatabaseMarker::clear();
    }

    /**
     * @throws DatabaseException When the marker table cannot be emptied
     */
    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        DatabaseMarker::clear();

        parent::tearDown();
    }

    public function testTheFirstReadWritesTheMarker(): void
    {
        $row = DatabaseMarker::ensure(self::NODE, $this->noPause());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row->marker);
        $this->assertSame(self::NODE, $row->writtenBy);
        $this->assertNotSame('', $row->writtenAt);
    }

    public function testASecondReadReturnsTheSameRow(): void
    {
        $first = DatabaseMarker::ensure(self::NODE, $this->noPause());

        $second = DatabaseMarker::ensure(self::OTHER_NODE, $this->noPause());

        $this->assertEquals($first, $second, 'A marker is written once and read by everyone after');
    }

    public function testAMarkerAnotherNodeWroteIsReadAsItIs(): void
    {
        DatabaseMarker::put(new DatabaseMarkerRow(self::OTHER_MARKER, self::OTHER_NODE, self::OTHER_WRITTEN_AT));

        $row = DatabaseMarker::ensure(self::NODE, $this->noPause());

        $this->assertSame(self::OTHER_MARKER, $row->marker);
        $this->assertSame(self::OTHER_NODE, $row->writtenBy);
        $this->assertSame(self::OTHER_WRITTEN_AT, $row->writtenAt);
    }

    public function testNoRowReadsAsNoMarker(): void
    {
        $this->assertNull(DatabaseMarker::current());
    }

    public function testPutReplacesTheRowAndClearTakesItAway(): void
    {
        DatabaseMarker::ensure(self::NODE, $this->noPause());

        DatabaseMarker::put(new DatabaseMarkerRow(self::OTHER_MARKER, self::OTHER_NODE, self::OTHER_WRITTEN_AT));
        $this->assertSame(self::OTHER_MARKER, DatabaseMarker::current()?->marker, 'put() writes over the row there');

        DatabaseMarker::clear();
        $this->assertNull(DatabaseMarker::current());
    }

    public function testTheMarkerIsReadOnThePrimaryConnectionAndTheCallerKeepsItsOwn(): void
    {
        $written = DatabaseMarker::ensure(self::NODE, $this->noPause());
        $this->configureFreshConnection();
        Database::useConnection(self::FRESH_INDEX);
        try {
            $read = DatabaseMarker::current();

            $this->assertSame(self::FRESH_INDEX, Database::getCurrentIndex(), 'The caller stands where it stood');
        } finally {
            Database::close(self::FRESH_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }

        $this->assertEquals($written, $read);
    }

    public function testTheMarkerTableIsCreatedWhereTheMigrationTableAlreadyIs(): void
    {
        Database::sql('DROP TABLE IF EXISTS `' . DatabaseMarker::TABLE . '`');
        $this->assertNull(DatabaseMarker::current(), 'No table reads as no marker');

        $this->configureFreshConnection();
        Database::useConnection(self::FRESH_INDEX);
        try {
            Migration::initialize();
        } finally {
            Database::close(self::FRESH_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }

        Database::sql(DatabaseSql::tableExistsProbe(DatabaseMarker::TABLE));
        $this->assertNull(DatabaseMarker::current(), 'The table must be there, and empty');
    }

    /**
     * A pause that fails the case: every read here finds its row without waiting.
     *
     * @return Closure(): void
     */
    private function noPause(): Closure
    {
        return function (): void {
            $this->fail('The marker waited, but nobody else was writing it');
        };
    }

    /**
     * Configures and connects a second connection to the test database, the one a restore or a
     * worker could be standing on.
     *
     * @throws DatabaseException When the connection cannot be opened
     */
    private function configureFreshConnection(): void
    {
        Database::configure(
            index: self::FRESH_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(self::FRESH_INDEX);
    }
}
