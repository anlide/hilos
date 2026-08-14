<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\AnonymizationValidator;
use Hilos\Backup\Anonymization\ArchiveSchemaReader;
use Hilos\Backup\Anonymization\ArchiveTableSchema;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\SqlParamCollection;

/**
 * Integration test: the merged PII registry against the demo's live schema.
 *
 * The registry is a hand-written list of tables and columns, and the two ways it goes
 * wrong are invisible to a unit test. It can fall behind the schema - a migration adds a
 * table and nobody classifies it - and it can name a strategy the column cannot carry.
 * The first is what the coverage gate refuses at restore time, and this test asks that
 * gate the same question against the database the demo actually creates. The second no
 * gate can see, because it is the database's opinion of an `UPDATE`, so the second test
 * builds the whole pass and runs it.
 *
 * Runs on top of the test database raised by composer run test:db-reset; it neither
 * creates nor drops schema, reads information_schema, and rolls back everything it writes.
 */
final class PiiRegistryCoverageTest extends IntegrationTestCase
{
    /** The demo runs on the single primary connection. */
    private const int CONNECTION_INDEX = 0;

    /**
     * Lower bound on the tables read out of the live schema, so an introspection query
     * that returned nothing cannot pass as a covered schema. The demo carries 47 tables
     * today (46 from its migrations plus `migration`, which the migration runner creates);
     * this is a floor, because adding a table is allowed and adding a row for it is what
     * the coverage gate then demands.
     */
    private const int MIN_LIVE_TABLE_COUNT = 47;

    /**
     * Lower bound on the statements the pass builds, for the same reason: a registry whose
     * rows all lost their columns would still validate (an empty column map is a legal
     * classification) and would then execute nothing at all.
     */
    private const int MIN_PASS_STATEMENT_COUNT = 15;

    /**
     * Salt the statements under test are built with. Fixed rather than minted, because a
     * test that fails only on some runs is worse than one that never exercises a random
     * salt; the salt's own randomness is the restore's business ({@see AnonymizationSqlBuilder}).
     */
    private const string TEST_SALT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** Name the probe row is written with, so the pass has a row to rewrite. */
    private const string PROBE_USER_NAME = 'PII coverage probe';

    /** `information_schema` result keys. */
    private const string COL_TABLE_NAME = 'TABLE_NAME';
    private const string COL_COLUMN_NAME = 'COLUMN_NAME';
    private const string COL_IS_NULLABLE = 'IS_NULLABLE';
    private const string COL_MAX_LENGTH = 'CHARACTER_MAXIMUM_LENGTH';
    private const string COL_KEY = 'COLUMN_KEY';

    /** `IS_NULLABLE` marker for a column that accepts NULL. */
    private const string NULLABLE_YES = 'YES';

    /** `COLUMN_KEY` marker for a column of the primary key. */
    private const string KEY_PRIMARY = 'PRI';

    /**
     * Every table the demo creates must carry a classification.
     *
     * The assertion is the real {@see AnonymizationValidator}, not a comparison written
     * here: the gate a restore trips is the only judge whose verdict means anything, and a
     * second implementation of it would be free to be more forgiving than the first.
     *
     * @throws AnonymizationConfigException When a table is unclassified, or a declared column
     *     is absent or cannot carry its strategy
     * @throws DatabaseException When an introspection query fails
     */
    public function testRegistryCoversLiveSchema(): void
    {
        $schemas = self::liveSchemas();

        $this->assertGreaterThanOrEqual(
            self::MIN_LIVE_TABLE_COUNT,
            count($schemas),
            'The live schema read back fewer tables than the demo creates; the introspection '
            . 'query, not the registry, is what to look at first',
        );

        AnonymizationValidator::validate(
            PiiRegistry::fromCatalog(),
            [self::CONNECTION_INDEX => $schemas],
        );
    }

    /**
     * Every statement the pass would run must be one the database accepts.
     *
     * What the gate cannot know, because it reads a schema rather than asks the server: a
     * strategy whose value the column's type refuses, a purge held back by an incoming
     * RESTRICT key, a hash too long for the column it goes back into. The statements are
     * built exactly as a restore builds them and run in archive order, which is the order
     * that decides whether a purged parent meets its children first.
     *
     * @throws AnonymizationConfigException When a strategy cannot be expressed for its column
     * @throws DatabaseException When a statement of the pass is refused
     */
    public function testPassStatementsExecute(): void
    {
        $registry = PiiRegistry::fromCatalog();
        $builder = new AnonymizationSqlBuilder(self::TEST_SALT);
        $statements = [];
        foreach (self::liveSchemas() as $schema) {
            if ($registry->isPurged(self::CONNECTION_INDEX, $schema->table)) {
                $statements[] = $builder->purgeStatement($schema->table);

                continue;
            }
            $update = $builder->updateStatement(
                $schema,
                $registry->strategiesFor(self::CONNECTION_INDEX, $schema->table) ?? [],
            );
            if ($update !== null) {
                $statements[] = $update;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_PASS_STATEMENT_COUNT,
            count($statements),
            'The pass built fewer statements than the registry declares work for',
        );

        Database::transactionStart();
        try {
            $probeId = self::insertProbeUser();
            foreach ($statements as $statement) {
                Database::sqlRun($statement);
            }
            // Executing on an empty table proves only that the statement parses, and most of
            // this schema is empty after a reset. One row of the demo's own is enough to also
            // prove the pass rewrites rather than passes over.
            $this->assertSame('User ' . $probeId, self::probeUserName($probeId));
        } finally {
            Database::transactionRollback();
        }
    }

    /**
     * Reads the live schema in the shape the archive reader produces.
     *
     * The tables come back in name order, which is the order mysqldump writes them and
     * therefore the order the pass runs in. Lengths are taken as `information_schema`
     * reports them: {@see ArchiveSchemaReader} leaves a `text` column without one while
     * this reports its maximum, and the difference cannot reach the SQL, because a length
     * is only ever used to truncate a hash and both values are far wider than one.
     *
     * @return list<ArchiveTableSchema> Live tables, in name order
     * @throws DatabaseException When an introspection query fails
     */
    private static function liveSchemas(): array
    {
        Database::sql(
            'SELECT ' . self::COL_TABLE_NAME . ', ' . self::COL_COLUMN_NAME . ', '
            . self::COL_IS_NULLABLE . ', ' . self::COL_MAX_LENGTH . ', ' . self::COL_KEY
            . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' ORDER BY ' . self::COL_TABLE_NAME . ', ORDINAL_POSITION',
        );

        $columns = [];
        $lengths = [];
        $primaryKeys = [];
        foreach (Database::rows() as $row) {
            $table = (string)$row[self::COL_TABLE_NAME];
            $column = (string)$row[self::COL_COLUMN_NAME];
            $length = $row[self::COL_MAX_LENGTH];
            $columns[$table][$column] = $row[self::COL_IS_NULLABLE] === self::NULLABLE_YES;
            $lengths[$table][$column] = $length === null ? null : (int)$length;
            $primaryKeys[$table] ??= [];
            if ($row[self::COL_KEY] === self::KEY_PRIMARY) {
                $primaryKeys[$table][] = $column;
            }
        }

        $schemas = [];
        foreach ($columns as $table => $tableColumns) {
            $schemas[] = new ArchiveTableSchema(
                (string)$table,
                $tableColumns,
                $lengths[$table],
                $primaryKeys[$table],
            );
        }

        return $schemas;
    }

    /**
     * Writes one row the pass has something to do to, inside the caller's transaction.
     *
     * Written with raw SQL rather than through the ORM: the row exists to be rewritten by
     * a statement the database runs, so anything the object layer would put between the
     * two would be measuring itself. The key is read back by name rather than taken from
     * `lastInsertId()`, which a prepared insert leaves at zero.
     *
     * @return int Primary key of the written row
     * @throws DatabaseException When the insert or the read-back fails
     */
    private static function insertProbeUser(): int
    {
        Database::sqlRun(
            'INSERT INTO `user` (`name`) VALUES (?)',
            SqlParamCollection::fromArray([self::PROBE_USER_NAME]),
        );
        Database::sql(
            'SELECT `id` FROM `user` WHERE `name` = ? ORDER BY `id` DESC LIMIT 1',
            SqlParamCollection::fromArray([self::PROBE_USER_NAME]),
        );

        return (int)Database::field('id');
    }

    /**
     * @param int $probeId Primary key of the probe row
     * @return ?string Name the probe row currently carries, or null when it is gone
     * @throws DatabaseException When the query fails
     */
    private static function probeUserName(int $probeId): ?string
    {
        Database::sql(
            'SELECT `name` FROM `user` WHERE `id` = ?',
            SqlParamCollection::fromArray([$probeId]),
        );
        $name = Database::field('name');

        return $name === null ? null : (string)$name;
    }
}
