<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Tests\Integration;

use Hilos\Backup\Anonymization\AnonymizationCompatibilityValidator;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;

/**
 * Integration test: the collected PII registry against every database the demo creates.
 *
 * Every Entity a mounted collection carries declares its own personal-data verdict, on the
 * database it lives in: the framework tables on the primary, the change log journal's on the
 * journal's own database. The registry can fall behind the schema - a migration adds a table
 * or a column and nobody classifies it - and it can name a strategy the column cannot carry.
 * Both are invisible to a unit test, so the case asks the gates a restore would ask, on each
 * configured connection.
 *
 * The demo declares no backup, so this is the declarations' check alone: no startup gate and
 * no anonymization pass, which stay with the demos that switch the feature on.
 *
 * Runs on top of the test database raised by composer run test:db-reset; it neither creates
 * nor drops schema and reads information_schema only.
 */
final class PiiRegistryCoverageTest extends IntegrationTestCase
{
    /**
     * Lower bound on the tables read out of each live schema, so an introspection query that
     * returned nothing cannot pass as a covered schema. The demo carries 68 tables on the
     * primary today, every one of them the framework's, and the journal's 6 on its own
     * database; these are floors, because adding a table is allowed and adding a verdict for
     * it is what the coverage gate then demands.
     */
    private const array MIN_LIVE_TABLE_COUNTS = [
        DatabaseConnectionDefaults::PRIMARY_INDEX => 68,
        ChangeLogDatabase::CONNECTION_INDEX => 6,
    ];

    /**
     * Every table each database holds must carry a classification, and every column it
     * classifies must be able to carry its strategy.
     *
     * The assertions are the real gates, not comparisons written here: the gates a restore
     * trips are the only judges whose verdict means anything, and a second implementation of
     * them would be free to be more forgiving than the first.
     *
     * @throws AnonymizationConfigException When a table is unclassified, or a declared column
     *     is absent or cannot carry its strategy
     * @throws DatabaseException When an introspection query fails
     */
    public function testRegistryCoversEveryLiveSchema(): void
    {
        $registry = PiiRegistry::collect();
        self::assertSame(array_keys(self::MIN_LIVE_TABLE_COUNTS), Database::getConfiguredIndices());
        try {
            foreach (self::MIN_LIVE_TABLE_COUNTS as $index => $minimum) {
                $schemas = LiveSchemaReader::read($index);
                $this->assertGreaterThanOrEqual(
                    $minimum,
                    count($schemas),
                    "Connection {$index} read back fewer tables than the demo creates; the "
                    . 'introspection query, not the registry, is what to look at first',
                );
                AnonymizationCoverageValidator::validateArchiveTables($registry, [$index => array_keys($schemas)]);
                AnonymizationCompatibilityValidator::validate($registry, $index, $schemas, self::maxPrimaryKey(...));
            }
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * Reads the largest primary key a table holds, the way a restore reads it, on the
     * connection the schema was read from.
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
}
