<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCompatibilityValidator;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\LiveSchemaReader;
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

    /**
     * Every table the demo creates must carry a classification, and every column it
     * classifies must be able to carry its strategy.
     *
     * The assertions are the real gates, not comparisons written here: the gates a restore
     * trips are the only judges whose verdict means anything, and a second implementation
     * of them would be free to be more forgiving than the first. The coverage gate is fed
     * the live table names, which for a restore into this demo is what the archive would
     * carry; the compatibility gate is fed the live schema, which is what it is for.
     *
     * @throws AnonymizationConfigException When a table is unclassified, or a declared column
     *     is absent or cannot carry its strategy
     * @throws DatabaseException When an introspection query fails
     */
    public function testRegistryCoversLiveSchema(): void
    {
        $schemas = LiveSchemaReader::read(self::CONNECTION_INDEX);

        $this->assertGreaterThanOrEqual(
            self::MIN_LIVE_TABLE_COUNT,
            count($schemas),
            'The live schema read back fewer tables than the demo creates; the introspection '
            . 'query, not the registry, is what to look at first',
        );

        $registry = PiiRegistry::fromCatalog();
        AnonymizationCoverageValidator::validate(
            $registry,
            [self::CONNECTION_INDEX => array_keys($schemas)],
        );
        AnonymizationCompatibilityValidator::validate(
            $registry,
            self::CONNECTION_INDEX,
            $schemas,
            self::maxPrimaryKey(...),
        );
    }

    /**
     * Every statement the pass would run must be one the database accepts.
     *
     * What no gate can know, because it reads a schema rather than asks the server: a value
     * the column refuses for a reason its declaration does not show, and a purge held back
     * by an incoming RESTRICT key (P-070). The statements are built exactly as a restore
     * builds them and run in the registry's declaration order, which is the order that
     * decides whether a purged parent meets its children first.
     *
     * @throws AnonymizationConfigException When a strategy cannot be expressed for its column
     * @throws DatabaseException When a statement of the pass is refused
     */
    public function testPassStatementsExecute(): void
    {
        $registry = PiiRegistry::fromCatalog();
        $schemas = LiveSchemaReader::read(self::CONNECTION_INDEX);
        $builder = new AnonymizationSqlBuilder(self::TEST_SALT);
        $statements = [];
        foreach ($registry->declaredTables(self::CONNECTION_INDEX) as $table) {
            $schema = $schemas[$table] ?? null;
            if ($schema === null) {
                continue;
            }
            if ($registry->isPurged(self::CONNECTION_INDEX, $table)) {
                $statements[] = $builder->purgeStatement($table);

                continue;
            }
            $update = $builder->updateStatement(
                $schema,
                $registry->strategiesFor(self::CONNECTION_INDEX, $table) ?? [],
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
     * Reads the largest primary key a table holds, the way a restore reads it.
     *
     * @param string $table Table to read
     * @param string $column Its single primary key column
     * @return int Largest key value, or 0 when the table holds no rows
     * @throws DatabaseException When the query fails
     */
    private static function maxPrimaryKey(string $table, string $column): int
    {
        Database::sql(AnonymizationSqlBuilder::maxPrimaryKeyStatement($table, $column));

        return (int)Database::field(AnonymizationSqlBuilder::MAX_PRIMARY_KEY_ALIAS);
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
