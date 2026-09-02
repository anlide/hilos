<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\RestrictingForeignKey;
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
 * column) and the reader is asked about it. Four children point at it, one per `ON DELETE`
 * rule, because the rule is filtered in SQL: a unit test would be checking its own stub.
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
     * Children of the probe table, one per `ON DELETE` rule. Dropped before the parent and
     * raised after it, in this order.
     */
    private const string CHILD_RESTRICT = 'hilos_fw_test_live_schema_child_restrict';
    private const string CHILD_NO_ACTION = 'hilos_fw_test_live_schema_child_no_action';
    private const string CHILD_CASCADE = 'hilos_fw_test_live_schema_child_cascade';
    private const string CHILD_SET_NULL = 'hilos_fw_test_live_schema_child_set_null';

    /**
     * Foreign keys of those children. The two forbidding ones are named so that the order
     * the reader returns them in - by key name - is the order the assertions read.
     */
    private const string KEY_RESTRICT = 'hilos_fw_test_lsr_one_restrict';
    private const string KEY_NO_ACTION = 'hilos_fw_test_lsr_two_no_action';
    private const string KEY_CASCADE = 'hilos_fw_test_lsr_three_cascade';
    private const string KEY_SET_NULL = 'hilos_fw_test_lsr_four_set_null';

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

        self::dropProbeTables();
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

        self::createChild(
            self::CHILD_RESTRICT,
            '`parent_id` INT UNSIGNED NOT NULL',
            'CONSTRAINT `' . self::KEY_RESTRICT . '` FOREIGN KEY (`parent_id`) REFERENCES '
            . self::PROBE_TABLE . ' (`id`) ON DELETE RESTRICT',
        );
        // This child declares `login` before `tenant`, so the order the reader answers its
        // composite key with can only have come from the key and not from the table.
        self::createChild(
            self::CHILD_NO_ACTION,
            '`login` VARCHAR(64) NOT NULL, `tenant` INT UNSIGNED NOT NULL',
            'CONSTRAINT `' . self::KEY_NO_ACTION . '` FOREIGN KEY (`tenant`, `login`) REFERENCES '
            . self::PROBE_TABLE . ' (`tenant`, `login`) ON DELETE NO ACTION',
        );
        self::createChild(
            self::CHILD_CASCADE,
            '`parent_id` INT UNSIGNED NOT NULL',
            'CONSTRAINT `' . self::KEY_CASCADE . '` FOREIGN KEY (`parent_id`) REFERENCES '
            . self::PROBE_TABLE . ' (`id`) ON DELETE CASCADE',
        );
        self::createChild(
            self::CHILD_SET_NULL,
            '`parent_id` INT UNSIGNED NULL',
            'CONSTRAINT `' . self::KEY_SET_NULL . '` FOREIGN KEY (`parent_id`) REFERENCES '
            . self::PROBE_TABLE . ' (`id`) ON DELETE SET NULL',
        );
    }

    /**
     * Drops the probe tables and closes the connection (via parent).
     *
     * @throws DatabaseException When the drop fails
     */
    protected function tearDown(): void
    {
        if (Database::isConnected()) {
            self::dropProbeTables();
        }
        parent::tearDown();
    }

    /**
     * Drops the probe table and its children, children first as the keys demand.
     *
     * @throws DatabaseException When a drop fails
     */
    private static function dropProbeTables(): void
    {
        foreach (
            [
                self::CHILD_RESTRICT,
                self::CHILD_NO_ACTION,
                self::CHILD_CASCADE,
                self::CHILD_SET_NULL,
                self::PROBE_TABLE,
            ] as $table
        ) {
            Database::sql('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * Raises one child of the probe table, carrying a single foreign key into it.
     *
     * @param string $table Child table name
     * @param string $columns Column definitions of the child, without the key
     * @param string $key The `CONSTRAINT ... FOREIGN KEY ... ON DELETE ...` clause
     * @throws DatabaseException When the child table cannot be raised
     */
    private static function createChild(string $table, string $columns, string $key): void
    {
        Database::sql(
            'CREATE TABLE ' . $table . ' (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, ' . $columns
            . ', PRIMARY KEY (`id`), ' . $key . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
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
     * One column of a unique index is enough for the rewrite to reach into it.
     *
     * @throws DatabaseException When the read fails
     */
    public function testReportsTheUniqueIndexesASetOfColumnsTouches(): void
    {
        $schema = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX)[self::PROBE_TABLE];

        $this->assertSame(['tenant', 'login'], $schema->uniqueIndexes[self::PAIR_INDEX] ?? []);
        $this->assertSame([self::PAIR_INDEX => ['tenant']], $schema->uniqueIndexesTouchedBy(['tenant']));
        $this->assertSame([self::PAIR_INDEX => ['login']], $schema->uniqueIndexesTouchedBy(['login']));
        $this->assertSame(
            [self::PAIR_INDEX => ['tenant', 'login']],
            $schema->uniqueIndexesTouchedBy(['login', 'tenant']),
        );
        $this->assertSame(['PRIMARY' => ['id']], $schema->uniqueIndexesTouchedBy(['id']));

        // A non-unique index is not one of them: repeating a value there collides with nothing.
        $this->assertArrayNotHasKey('hilos_fw_test_live_schema_note', $schema->uniqueIndexes);
    }

    /**
     * Only the incoming keys that forbid deleting a parent row are read back.
     *
     * @throws DatabaseException When the read fails
     */
    public function testReadsTheIncomingKeysThatForbidDeletingAParentRow(): void
    {
        $schema = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX)[self::PROBE_TABLE];

        // CASCADE and SET NULL are not among them: their children let the parent row go, one
        // by following it and the other by forgetting it.
        $this->assertSame(
            [self::KEY_RESTRICT, self::KEY_NO_ACTION],
            array_map(static fn(RestrictingForeignKey $key): string => $key->constraint, $schema->restrictingKeys),
        );

        [$restrict, $noAction] = $schema->restrictingKeys;

        $this->assertSame(self::CHILD_RESTRICT, $restrict->childTable);
        $this->assertSame(['parent_id'], $restrict->childColumns);
        $this->assertSame('RESTRICT', $restrict->deleteRule);

        $this->assertSame(self::CHILD_NO_ACTION, $noAction->childTable);
        $this->assertSame(['tenant', 'login'], $noAction->childColumns);
        $this->assertSame('NO ACTION', $noAction->deleteRule);
    }

    /**
     * A table nobody points at carries no keys at all.
     *
     * @throws DatabaseException When the read fails
     */
    public function testATableNothingReferencesCarriesNoRestrictingKeys(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);

        $this->assertSame([], $schemas[self::CHILD_RESTRICT]->restrictingKeys);
    }
}
