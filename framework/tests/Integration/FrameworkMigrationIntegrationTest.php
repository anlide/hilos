<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use FilesystemIterator;
use Hilos\Constants\EnvConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\MigrationMarkedFailedException;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPair;
use Hilos\Database\Migration\FrameworkMigrationPart;
use Hilos\Database\Migration\FrameworkMigrationProject;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration\FrameworkSchemaUnit;
use Hilos\Database\MigrationClaim;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/**
 * Source transport and startup against real MariaDB, through the ordinary primary Migration.
 * Each case owns a disposable database pair; the suite's ledger is never renamed or emptied.
 * SQL here arranges infrastructure faults and observes DDL, rather than replacing the migrator.
 */
final class FrameworkMigrationIntegrationTest extends FrameworkIntegrationTestCase
{
    private const int ANCHOR = 9400;
    private const string CHANGE = '20261020120000000000_HIL-1518_integration';
    private const string LATE_CHANGE = '20261010120000000000_HIL-1518_late';
    private const string USER_TABLE = 'hilos_fw_catalog_user';
    private const string SESSION_TABLE = 'hilos_fw_catalog_session';
    private const string JOURNAL_TABLE = 'hilos_fw_catalog_journal';

    private string $root;
    private string $database;
    private string $journal;
    private FrameworkMigrationProject $project;
    private FrameworkSchemaCatalog $schema;

    /** @var array<string, mixed> Private migration configuration with no public snapshot/reset API */
    private array $previousMigration = [];

    /**
     * Opens a fresh primary database on the existing stand and snapshots process-local track settings.
     *
     * @throws HilosException When fixture connection or schema setup fails
     * @throws JsonException When fixture catalog encoding fails
     */
    protected function setUp(): void
    {
        parent::setUp();
        FrameworkMigrationStartupGuard::resetForTests();
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        // There is no public reset for this connection-local bootstrap cache. A fresh
        // fixture database must be initialized as a fresh CLI process would initialize it.
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        $this->database = 'hilos-catalog-it-' . bin2hex(random_bytes(6));
        $this->journal = ChangeLogDatabase::name($this->database);
        $this->root = sys_get_temp_dir() . '/' . $this->database;
        mkdir($this->root . '/backend/Database/Migration/Schema', 0700, true);
        mkdir($this->root . '/source/Stub', 0700, true);
        mkdir($this->root . '/source/Change');
        $this->configureDatabase(DatabaseConnectionDefaults::PRIMARY_INDEX, $this->database, create: true);
        $this->project = new FrameworkMigrationProject($this->root . '/backend/Database/Migration/Schema', CatalogIntegrationHilos::class);
        $this->schema = new CatalogIntegrationSchema($this->root . '/source');
        CatalogIntegrationGuard::$schema = $this->schema;
        CatalogIntegrationGuard::$sourceRoot = $this->root . '/source';
        Migration::setMigrationListPath($this->root . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        file_put_contents($this->project->root . '/' . self::ANCHOR . '_legacy.sql', "SELECT 1;\n");
        $genesis = [];
        foreach ($this->schema->allUnits() as $unit) {
            if (!$unit->bootstrapOwned) {
                $table = $unit->tables[0];
                $qualified = "`{$table}`";
                if ($unit->connection === ChangeLogDatabase::CONNECTION_INDEX) {
                    $qualified = '{{change_log_database}}.' . $qualified;
                }
                file_put_contents($unit->upPath, "CREATE TABLE {$qualified} (`id` INT PRIMARY KEY);\n");
                if ($unit->connection === ChangeLogDatabase::CONNECTION_INDEX) {
                    file_put_contents($unit->upPath, "INSERT INTO {$qualified} (`id`) VALUES (7);\n", FILE_APPEND);
                }
                file_put_contents($unit->downPath, "DROP TABLE {$qualified};\n");
            }
            $genesis[] = [
                'unit' => $unit->key,
                'upSha256' => $unit->bootstrapOwned ? null : hash_file('sha256', $unit->upPath),
                'downSha256' => $unit->bootstrapOwned ? null : hash_file('sha256', $unit->downPath),
            ];
        }
        file_put_contents($this->root . '/source/catalog.json', json_encode(['format' => 1, 'units' => $genesis], JSON_THROW_ON_ERROR));
    }

    /**
     * Drops only this case's pair and restores every migration setting, including an initially unconfigured path.
     *
     * @throws DatabaseException When the fixture schemas cannot be removed
     */
    protected function tearDown(): void
    {
        try {
            FrameworkMigrationStartupGuard::resetForTests();
            CatalogIntegrationGuard::$schema = null;
            CatalogIntegrationGuard::$sourceRoot = null;
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sql('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->journal));
            Database::sql('DROP DATABASE IF EXISTS ' . ChangeLogDatabase::identifier($this->database));
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            ) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        } finally {
            Migration::setMigrationName($this->previousMigration['migrationName']);
            if ($this->previousMigration['migrationListPath'] === null) {
                // The public path setter cannot restore an unconfigured path.
                new ReflectionProperty(Migration::class, 'migrationListPath')->setValue(null, null);
            } else {
                Migration::setMigrationListPath($this->previousMigration['migrationListPath']);
            }
            new ReflectionProperty(Migration::class, 'initialized')->setValue(null, $this->previousMigration['initialized']);
            parent::tearDown();
        }
    }

    /**
     * Old installs survive a new stub; copied SQL still has to run before Hilos initialization.
     *
     * @throws HilosException When source transport or ordinary migration fails
     * @throws JsonException When fixture change encoding fails
     */
    public function testCopyThenOrdinaryRolloutOpensStartupAndRepeatsWithoutNewFiles(): void
    {
        $this->installCore();
        $this->assertSame(3, Migration::migrateUp());
        $this->change(self::CHANGE, 'user', 'label');
        $this->assertSame(
            'Framework migration ' . self::CHANGE . '/user is missing from this project; run db:framework:pull',
            $this->sourceRefusal(),
        );
        $file = $this->pullOne();
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        $this->assertUnapplied($file);
        try {
            CatalogIntegrationHilos::init();
            $this->fail('Direct startup accepted pending framework SQL');
        } catch (DatabaseException $refusal) {
            $this->assertSame($this->unappliedMessage($file), $refusal->getMessage());
        }
        $this->assertNull($this->column(self::USER_TABLE, 'label'));
        $hooks = 0;
        $this->assertSame(1, Migration::migrateUp(afterRollout: function () use (&$hooks): void {
            ++$hooks;
            $this->assertNotNull(MigrationClaim::current());
            FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        }));
        $this->assertSame(1, $hooks);
        $this->assertNull(MigrationClaim::current());
        $this->assertSame('varchar(32)', $this->column(self::USER_TABLE, 'label'));
        Database::sql('SELECT `id`, `label` FROM `' . self::USER_TABLE . '`');
        $this->assertSame([['id' => '2', 'label' => 'delta']], Database::rows());
        $this->assertSame([9400, 9401, 9402, 9403], Migration::appliedIndices());
        $this->assertRepeatIsEmpty();
    }

    /**
     * Pending origins are refused even before the ledger exists, without bootstrap DDL.
     *
     * @throws HilosException When source or connection setup fails
     */
    public function testAbsentLedgerIsReadOnlyAndRestoresTheActiveSecondaryConnection(): void
    {
        $this->installCore();
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        $this->configureDatabase(ChangeLogDatabase::CONNECTION_INDEX, $this->journal, create: true);
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        $this->assertSame([], Migration::appliedIndices());
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        $this->assertUnapplied('9401_framework_install_user.sql');
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        $this->assertSame([], $this->tables($this->database));
        $this->assertSame([], $this->tables($this->journal));
    }

    /**
     * Only a missing table is treated as an empty ledger; other SQL failures survive the read.
     *
     * @throws HilosException When fixture connection or schema setup fails
     */
    public function testMalformedLedgerPropagatesItsErrorAndRestoresTheActiveConnection(): void
    {
        Database::sql('CREATE TABLE `migration` (`index` INT PRIMARY KEY)');
        $this->configureDatabase(ChangeLogDatabase::CONNECTION_INDEX, $this->journal, create: true);
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            Migration::appliedIndices();
            $this->fail('A malformed migration table was accepted as an absent table');
        } catch (DatabaseException $refusal) {
            $this->assertSame(1054, $refusal->getMysqlErrorCode());
        }
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        $this->assertSame(['migration'], $this->tables($this->database));
    }

    /**
     * Successful exact rows come from PRIMARY even when the active connection has its own ledger.
     *
     * @throws HilosException When fixture connection or ledger setup fails
     */
    public function testExactSuccessfulRowsAreReadFromPrimary(): void
    {
        Migration::initialize();
        Database::sql('INSERT INTO `migration` (`index`, `failed`) VALUES (9401, 0), (9402, 1), (9404, 0)');
        $this->configureDatabase(ChangeLogDatabase::CONNECTION_INDEX, $this->journal, create: true);
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        Database::sql('CREATE TABLE `migration` (`index` INT PRIMARY KEY, `failed` TINYINT)');
        Database::sql('INSERT INTO `migration` VALUES (9499, 0)');
        $this->assertSame([9401, 9404], Migration::appliedIndices());
        $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
    }

    /**
     * A real failing migration leaves its exact failed row and cannot pass either startup or normal retry policy.
     *
     * @throws HilosException When source setup or successful rollout fails
     * @throws JsonException When fixture change encoding fails
     */
    public function testFailedDeltaIsNotSuccessfulAndTheClaimIsReleased(): void
    {
        $this->installCore();
        $this->assertSame(3, Migration::migrateUp());
        $this->change(self::CHANGE, 'user', 'label', fail: true);
        $file = $this->pullOne();
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        try {
            Migration::migrateUp();
            $this->fail('The fixture SQL must fail');
        } catch (DatabaseException $refusal) {
            $this->assertStringContainsString('Migration 9403 failed', $refusal->getMessage());
        }
        Database::sql('SELECT `failed` FROM `migration` WHERE `index` = 9403');
        $this->assertSame(1, (int)Database::field('failed'));
        $this->assertSame([9400, 9401, 9402], Migration::appliedIndices());
        $this->assertUnapplied($file);
        $this->assertNull($this->column(self::USER_TABLE, 'label'));
        $this->assertNull(MigrationClaim::current());
        try {
            Migration::migrateUp();
            $this->fail('A failed migration must require explicit retry');
        } catch (MigrationMarkedFailedException $refusal) {
            $this->assertStringContainsString('db:migration:retry 9403', $refusal->getMessage());
        }
        $this->assertNull(MigrationClaim::current());
    }

    /**
     * Rollback removes the exact successful row and reverses both the column and the data.
     *
     * @throws HilosException When source setup or ordinary migration fails
     * @throws JsonException When fixture change encoding fails
     */
    public function testRollbackClosesThePreparedStartupAgain(): void
    {
        $this->installCore();
        $this->assertSame(3, Migration::migrateUp());
        $this->change(self::CHANGE, 'user', 'label');
        $file = $this->pullOne();
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        $this->assertSame(1, Migration::migrateUp());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame(1, Migration::migrateDown(9402));
        $this->assertSame([9400, 9401, 9402], Migration::appliedIndices());
        $this->assertNull($this->column(self::USER_TABLE, 'label'));
        Database::sql('SELECT COUNT(*) AS n FROM `' . self::USER_TABLE . '`');
        $this->assertSame(0, (int)Database::field('n'));
        $this->assertUnapplied($file);
        $this->assertSame(1, Migration::migrateUp());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
    }

    /**
     * MAX cannot stand in for the first origin row, even below the lowest remaining ledger row.
     *
     * @throws HilosException When source setup or ledger access fails
     */
    public function testMissingLowerOriginIsRefusedDespiteAHigherSuccessfulIndex(): void
    {
        $this->installCore();
        $this->assertSame(3, Migration::migrateUp());
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        Database::sql('DELETE FROM `migration` WHERE `index` <= 9401');
        $this->assertSame(9402, Migration::getCurrentIndex());
        $this->assertSame([9402], Migration::appliedIndices());
        $this->assertUnapplied('9401_framework_install_user.sql');
    }

    /**
     * A composite baseline covers named parts only; a subsequently landed earlier ID still needs its own SQL.
     *
     * @throws HilosException When source transport or ordinary migration fails
     * @throws JsonException When fixture change encoding fails
     */
    public function testBaselineExactCoverageDoesNotSwallowALateEarlierBranch(): void
    {
        $this->change(self::CHANGE, 'user', 'label');
        $catalog = $this->catalog();
        $up = file_get_contents($this->schema->unit('user')->upPath) . file_get_contents($this->schema->unit('session')->upPath);
        $down = file_get_contents($this->schema->unit('session')->downPath) . file_get_contents($this->schema->unit('user')->downPath);
        $this->publishPair('fixture_baseline', FrameworkMigrationOrigin::BASELINE, ['migration', 'user', 'session'],
            $up, $down, $catalog->partsForUnits(['user', 'session']));
        $baseline = file_get_contents($this->project->root . '/9401_fixture_baseline.sql');
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        $this->assertUnapplied('9401_fixture_baseline.sql');
        $this->assertSame(2, Migration::migrateUp());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame('varchar(32)', $this->column(self::USER_TABLE, 'label'));
        $this->change(self::LATE_CHANGE, 'session', 'branch_label');
        $this->assertSame(
            'Framework migration ' . self::LATE_CHANGE . '/session is missing from this project; run db:framework:pull',
            $this->sourceRefusal(),
        );
        $file = $this->pullOne();
        $track = (new FrameworkMigrationInstaller($this->schema, $this->catalog()))->readTrack($this->project, requireComplete: true);
        $this->assertSame([self::CHANGE . '/user'],
            array_map(static fn (FrameworkMigrationPart $part): string => $part->key(), $track[1]->origin->parts));
        $this->assertSame([self::LATE_CHANGE . '/session'],
            array_map(static fn (FrameworkMigrationPart $part): string => $part->key(), $track[2]->origin->parts));
        $this->assertSame($baseline, file_get_contents($this->project->root . '/9401_fixture_baseline.sql'));
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        $this->assertUnapplied($file);
        $this->assertSame(1, Migration::migrateUp());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame('varchar(32)', $this->column(self::SESSION_TABLE, 'branch_label'));
        $this->assertSame([9400, 9401, 9402], Migration::appliedIndices());
        $this->assertRepeatIsEmpty();
    }

    /**
     * A newly selected journal unit runs from PRIMARY, with a primary ledger row and no journal bootstrap tables.
     *
     * @throws HilosException When source transport, connection setup or migration fails
     */
    public function testJournalQualifiedInstallUsesThePrimaryTrackAndRolloutClaim(): void
    {
        $this->installCore();
        $this->assertSame(3, Migration::migrateUp());
        $this->configureDatabase(ChangeLogDatabase::CONNECTION_INDEX, $this->journal, create: true);
        $this->project = new FrameworkMigrationProject($this->project->root, CatalogJournalIntegrationHilos::class);
        $file = $this->pullOne();
        $this->assertStringContainsString('{{change_log_database}}', file_get_contents($this->project->root . '/' . $file));
        CatalogIntegrationGuard::prepare(CatalogJournalIntegrationHilos::class, $this->root);
        $this->assertUnapplied($file);
        $this->assertSame(1, Migration::migrateUp(afterRollout: function (): void {
            $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
            $this->assertNotNull(MigrationClaim::current());
            Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
            FrameworkMigrationStartupGuard::assertAppliedIfStarting();
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        }));
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        $this->assertSame([self::JOURNAL_TABLE], $this->tables($this->journal));
        Database::sql('SELECT `id` FROM ' . ChangeLogDatabase::identifier($this->journal) . '.`' . self::JOURNAL_TABLE . '`');
        $this->assertSame([['id' => '7']], Database::rows());
        $this->assertSame([9400, 9401, 9402, 9403], Migration::appliedIndices());
        $this->assertRepeatIsEmpty();
        $this->assertSame(1, Migration::migrateDown(9402));
        $this->assertSame([], $this->tables($this->journal));
        $this->assertUnapplied($file);
    }

    /**
     * A reviewed legacy adoption executes only comments and records its own successful index.
     *
     * @throws HilosException When source transport or ordinary migration fails
     */
    public function testReviewedNoOpAdoptionPreservesTheExistingSchemaAndRows(): void
    {
        file_put_contents($this->project->root . '/' . self::ANCHOR . '_legacy.sql',
            file_get_contents($this->schema->unit('user')->upPath)
            . file_get_contents($this->schema->unit('session')->upPath)
            . 'INSERT INTO `' . self::USER_TABLE . "` (`id`) VALUES (17);\n");
        $this->assertSame(1, Migration::migrateUp());
        $before = $this->tables($this->database);
        $this->publishPair('adopt_framework_catalog', FrameworkMigrationOrigin::ADOPT,
            ['migration', 'user', 'session'], "-- Reviewed genesis adoption, no DDL.\n", "-- Adoption has no schema rollback.\n");
        CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        $this->assertUnapplied('9401_adopt_framework_catalog.sql');
        $this->assertSame(1, Migration::migrateUp());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame($before, $this->tables($this->database));
        $this->assertNull($this->column(self::USER_TABLE, 'label'));
        Database::sql('SELECT `id` FROM `' . self::USER_TABLE . '`');
        $this->assertSame([['id' => '17']], Database::rows());
        $this->assertSame([9400, 9401], Migration::appliedIndices());
        $this->assertRepeatIsEmpty();
    }

    /**
     * Publishes reviewed genesis installs, using the same source writer as the command.
     *
     * @throws HilosException When source planning or publication fails
     */
    private function installCore(): void
    {
        foreach (['user', 'session'] as $key) {
            $unit = $this->schema->unit($key);
            $this->publishPair('framework_install_' . $key, FrameworkMigrationOrigin::INSTALL, [$key],
                file_get_contents($unit->upPath), file_get_contents($unit->downPath));
        }
    }

    /**
     * @param string $name Source stem without number
     * @param string $kind Reviewed origin kind
     * @param list<string> $units Exact unit set
     * @param string $up Reviewed up SQL
     * @param string $down Reviewed down SQL
     * @param list<FrameworkMigrationPart> $parts Exact covered parts
     * @throws HilosException When provenance or source publication fails
     */
    private function publishPair(string $name, string $kind, array $units, string $up, string $down, array $parts = []): void
    {
        $installer = new FrameworkMigrationInstaller($this->schema, $this->catalog());
        $pair = new FrameworkMigrationPair($name, FrameworkMigrationOrigin::forBodies($kind, $units, $parts, $up, $down), $up, $down);
        $publication = $installer->publish($installer->planSources($this->project, $pair));
        $this->assertFalse($publication->hasFailures(), $publication->projects[0]->failure?->getMessage() ?? 'Source pair publication');
        $this->assertCount(2, $publication->projects[0]->publishedPaths);
    }

    /**
     * Updates the current stub and records the immutable delta separately.
     *
     * @param string $id Exact catalog identity
     * @param string $key Unit receiving the column
     * @param string $column New fixture column
     * @param bool $fail Whether this fixture deliberately fails before its DDL
     * @throws DatabaseException When the fixture unit is unknown
     * @throws JsonException When fixture change encoding fails
     */
    private function change(string $id, string $key, string $column, bool $fail = false): void
    {
        $unit = $this->schema->unit($key);
        $table = $unit->tables[0];
        file_put_contents($unit->upPath,
            "CREATE TABLE `{$table}` (`id` INT PRIMARY KEY, `{$column}` VARCHAR(32));\n");
        $directory = $this->root . '/source/Change/' . $id;
        mkdir($directory);
        $up = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(32);\n"
            . "INSERT INTO `{$table}` (`id`, `{$column}`) VALUES (2, 'delta');\n";
        if ($fail) {
            $up = "SELECT * FROM `hilos_fw_catalog_absent`;\n" . $up;
        }
        file_put_contents($directory . '/up.sql', $up);
        file_put_contents($directory . '/down.sql', "DELETE FROM `{$table}` WHERE `id` = 2;\n"
            . "ALTER TABLE `{$table}` DROP COLUMN `{$column}`;\n");
        file_put_contents($directory . '/change.json', json_encode([
            'format' => 1, 'id' => $id,
            'parts' => [[
                'unit' => $key, 'up' => 'up.sql', 'down' => 'down.sql', 'requires' => [],
                'afterStubUpSha256' => hash_file('sha256', $unit->upPath),
                'afterStubDownSha256' => hash_file('sha256', $unit->downPath),
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return string Copied up basename; every pull in these scenarios has exactly one pending pair
     * @throws HilosException When the catalog or source plan is invalid
     */
    private function pullOne(): string
    {
        $installer = new FrameworkMigrationInstaller($this->schema, $this->catalog());
        $plan = $installer->plan($this->project)[0];
        $this->assertCount(1, $plan->publications);
        $publication = $installer->execute($this->project);
        $this->assertFalse($publication->hasFailures(), $publication->projects[0]->failure?->getMessage() ?? 'Catalog delta publication');
        $this->assertCount(2, $publication->projects[0]->publishedPaths);

        return basename($plan->publications[0]->upPath);
    }

    /**
     * The source writer and migrator both repeat without new artifacts, rows or SQL.
     *
     * @throws HilosException When catalog reads or ordinary migration fails
     */
    private function assertRepeatIsEmpty(): void
    {
        $before = [];
        foreach (glob($this->project->root . '/*.sql') as $file) {
            $before[$file] = file_get_contents($file);
        }
        $indices = Migration::appliedIndices();
        $publication = (new FrameworkMigrationInstaller($this->schema, $this->catalog()))->execute($this->project);
        $this->assertFalse($publication->hasFailures());
        $this->assertSame([], $publication->projects[0]->publishedPaths);
        $this->assertSame(array_keys($before), glob($this->project->root . '/*.sql'));
        foreach ($before as $file => $body) {
            $this->assertSame($body, file_get_contents($file));
        }
        $this->assertSame(0, Migration::migrateUp());
        $this->assertSame($indices, Migration::appliedIndices());
    }

    /**
     * @return FrameworkMigrationCatalog Fresh metadata after adding immutable fixture parts
     * @throws DatabaseException When fixture source metadata is invalid
     */
    private function catalog(): FrameworkMigrationCatalog
    {
        return new FrameworkMigrationCatalog($this->schema, $this->root . '/source');
    }

    /**
     * @return string Source-stage refusal from actual provenance and current stubs
     * @throws HilosException When fixture source inspection fails outside the expected DB refusal
     */
    private function sourceRefusal(): string
    {
        try {
            CatalogIntegrationGuard::prepare(CatalogIntegrationHilos::class, $this->root);
        } catch (DatabaseException $refusal) {
            return $refusal->getMessage();
        }
        $this->fail('Startup accepted incomplete catalog sources');
    }

    /** @param string $file Exact origin basename missing its successful row */
    private function assertUnapplied(string $file): void
    {
        try {
            FrameworkMigrationStartupGuard::assertAppliedIfStarting();
            $this->fail('Startup accepted an unapplied origin');
        } catch (DatabaseException $refusal) {
            $this->assertSame($this->unappliedMessage($file), $refusal->getMessage());
        }
    }

    /**
     * @param string $file Exact origin basename
     * @return string Approved startup refusal
     */
    private function unappliedMessage(string $file): string
    {
        return "Framework migration {$file} is not applied; run db:migration:up";
    }

    /**
     * @param int $index Fixture connection
     * @param string $database Isolated schema name
     * @param bool $create Whether to create the schema first
     * @throws HilosException When fixture connection or schema creation fails
     */
    private function configureDatabase(int $index, string $database, bool $create): void
    {
        Database::close($index);
        $configuration = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        if ($create) {
            // Connect as the stand's root user to create isolated schemas; no second server or process.
            Database::configure($index, $configuration->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
                $index === DatabaseConnectionDefaults::PRIMARY_INDEX ? $configuration->database : $this->database,
                $configuration->port, $configuration->charset);
            Database::connect($index);
            $previous = Database::getCurrentIndex();
            Database::useConnection($index);
            Database::sql('CREATE DATABASE ' . ChangeLogDatabase::identifier($database)
                . ' ' . DatabaseConnectionDefaults::createDatabaseCharsetClause());
            Database::useConnection($previous);
            Database::close($index);
        }
        Database::configure($index, $configuration->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
            $database, $configuration->port, $configuration->charset);
        Database::connect($index);
    }

    /**
     * @param string $database Schema to observe without bootstrapping it
     * @return list<string> Physical tables in name order
     * @throws DatabaseException When schema introspection fails
     */
    private function tables(string $database): array
    {
        Database::sql('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database]);

        return array_column(Database::rows(), 'name');
    }

    /**
     * @param string $table Fixture primary table
     * @param string $column Column to observe
     * @return ?string Physical type, absent before up and after down
     * @throws DatabaseException When schema introspection fails
     */
    private function column(string $table, string $column): ?string
    {
        Database::sql('SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->database, $table, $column]);

        return Database::row()['type'] ?? null;
    }
}

/** Only source factories are replaced; applied indices always come from the real Migration. */
final class CatalogIntegrationGuard extends FrameworkMigrationStartupGuard
{
    public static ?FrameworkSchemaCatalog $schema = null;
    public static ?string $sourceRoot = null;

    /** @return FrameworkSchemaCatalog Temporary fixture schema metadata */
    protected static function createSchemaCatalog(): FrameworkSchemaCatalog
    {
        return self::$schema;
    }

    /**
     * @param FrameworkSchemaCatalog $schema Temporary schema metadata
     * @return FrameworkMigrationCatalog Actual temporary source catalog
     * @throws DatabaseException When fixture source metadata is invalid
     */
    protected static function createMigrationCatalog(FrameworkSchemaCatalog $schema): FrameworkMigrationCatalog
    {
        return new FrameworkMigrationCatalog($schema, self::$sourceRoot);
    }
}

/** Fixture unit selection, independent of the production feature catalog already covered by unit tests. */
final class CatalogIntegrationSchema extends FrameworkSchemaCatalog
{
    /**
     * @param string $root Temporary stub directory parent
     * @throws DatabaseException When fixture schema metadata is invalid
     */
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    /**
     * @param class-string<Hilos> $hilosClass Primary-only or journal-enabled fixture declaration
     * @return list<FrameworkSchemaUnit> Selected fixture units
     */
    public function selectedUnits(string $hilosClass): array
    {
        return array_values(array_filter($this->allUnits(), static fn (FrameworkSchemaUnit $unit): bool =>
            $hilosClass === CatalogJournalIntegrationHilos::class || $unit->connection === DatabaseConnectionDefaults::PRIMARY_INDEX));
    }

    /**
     * @return list<FrameworkSchemaUnit> Fixture bootstrap, primary and derived units
     * @throws DatabaseException When fixture unit metadata is invalid
     */
    protected function buildUnits(): array
    {
        $units = [new FrameworkSchemaUnit('migration', ['migration'], null, null, bootstrapOwned: true)];
        foreach (['user', 'session', 'change_log'] as $key) {
            $units[] = new FrameworkSchemaUnit(
                $key,
                [$key === 'change_log' ? 'hilos_fw_catalog_journal' : 'hilos_fw_catalog_' . $key],
                $this->root . '/Stub/' . $key . '.sql',
                $this->root . '/Stub/' . $key . '_down.sql',
                connection: $key === 'change_log' ? ChangeLogDatabase::CONNECTION_INDEX : DatabaseConnectionDefaults::PRIMARY_INDEX,
            );
        }

        return $units;
    }
}

abstract class CatalogIntegrationHilos extends Hilos
{
}

abstract class CatalogJournalIntegrationHilos extends CatalogIntegrationHilos
{
}
