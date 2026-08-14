<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Environment\Exception\EnvException;

/**
 * Integration test: the live schema {@see LiveSchemaReader} reads back.
 *
 * The reader answers out of `information_schema`, so nothing but a real database can say
 * whether it answers correctly - a unit test would have to fake the very rows under test.
 * A probe table is raised carrying one of each question the compatibility gate asks
 * (nullability, type, length, a single primary key, a unique index over more than one
 * column) and the reader is asked about it.
 *
 * Run MariaDB first (composer run test:framework:up), then
 * composer run test:framework:integration.
 */
final class LiveSchemaReaderIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Probe table this test raises and drops. */
    private const string PROBE_TABLE = 'hilos_fw_test_live_schema';

    /** Unique index of the probe table that spans two columns. */
    private const string PAIR_INDEX = 'hilos_fw_test_live_schema_pair';

    /** Declared width of the probe table's bounded string column. */
    private const int EMAIL_LENGTH = 190;

    /**
     * Connects (via parent) and raises the probe table.
     *
     * @throws EnvException When env variables are missing or invalid
     * @throws DatabaseConnectionException When connect fails
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws DatabaseException When the probe table cannot be raised
     */
    protected function setUp(): void
    {
        parent::setUp();

        Database::sql('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
        Database::sql(
            'CREATE TABLE ' . self::PROBE_TABLE . ' ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`email` VARCHAR(' . self::EMAIL_LENGTH . ') NOT NULL,'
            . '`note` TEXT NULL,'
            . '`tenant` INT UNSIGNED NOT NULL,'
            . '`login` VARCHAR(64) NOT NULL,'
            . 'PRIMARY KEY (`id`),'
            . 'UNIQUE KEY `' . self::PAIR_INDEX . '` (`tenant`, `login`),'
            . 'KEY `hilos_fw_test_live_schema_note` (`note`(16))'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /**
     * Drops the probe table and closes the connection (via parent).
     *
     * @throws DatabaseException When the drop fails
     */
    protected function tearDown(): void
    {
        if (Database::isConnected()) {
            Database::sql('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
        }
        parent::tearDown();
    }

    /**
     * Every question the compatibility gate asks, answered off the live probe table.
     *
     * @throws DatabaseException When the read fails
     */
    public function testReadsColumnsKeysAndUniqueIndexesOfALiveTable(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);

        $this->assertArrayHasKey(self::PROBE_TABLE, $schemas);
        $schema = $schemas[self::PROBE_TABLE];

        $this->assertTrue($schema->hasColumn('email'));
        $this->assertFalse($schema->hasColumn('missing'));

        $this->assertFalse($schema->isNullable('email'));
        $this->assertTrue($schema->isNullable('note'));

        $this->assertSame('varchar', $schema->typeOf('email'));
        $this->assertSame('text', $schema->typeOf('note'));
        $this->assertSame('int', $schema->typeOf('id'));
        $this->assertNull($schema->typeOf('missing'));

        $this->assertSame(self::EMAIL_LENGTH, $schema->lengthOf('email'));
        $this->assertNull($schema->lengthOf('id'));

        $this->assertSame('id', $schema->singlePrimaryKey());
        $this->assertSame(['id'], $schema->primaryKey);
    }

    /**
     * A unique index counts as covered only once every one of its columns is rewritten.
     *
     * @throws DatabaseException When the read fails
     */
    public function testReportsTheUniqueIndexesASetOfColumnsCoversWhole(): void
    {
        $schema = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX)[self::PROBE_TABLE];

        $this->assertSame(['tenant', 'login'], $schema->uniqueIndexes[self::PAIR_INDEX] ?? []);
        $this->assertSame([], $schema->uniqueIndexesCoveredBy(['tenant']));
        $this->assertSame([self::PAIR_INDEX], $schema->uniqueIndexesCoveredBy(['login', 'tenant']));
        $this->assertSame(['PRIMARY'], $schema->uniqueIndexesCoveredBy(['id']));

        // A non-unique index is not one of them: repeating a value there collides with nothing.
        $this->assertArrayNotHasKey('hilos_fw_test_live_schema_note', $schema->uniqueIndexes);
    }
}
