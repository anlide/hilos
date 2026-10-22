<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use FilesystemIterator;
use Hilos\Constants\EnvConstants;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Migration;
use Hilos\Database\Migration\FrameworkMigrationCatalog;
use Hilos\Database\Migration\FrameworkMigrationInstaller;
use Hilos\Database\Migration\FrameworkMigrationOrigin;
use Hilos\Database\Migration\FrameworkMigrationPart;
use Hilos\Database\Migration\FrameworkMigrationProject;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Migration\FrameworkSchemaUnit;
use Hilos\Database\MigrationClaim;
use Hilos\Database\Schema\ChangeLogTablesWithoutEntity;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaMismatch;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

/**
 * Generates the actual composite baseline from private copies of the real stubs and catalog.
 * DDL runs through the primary Migration, outside a rollback scope, in an owned database pair.
 * Infrastructure SQL creates that pair and independently observes rows; it replaces no DbCollection API.
 * Fixture handles and snapshots belong to this case only, with no shared runtime reader or writer.
 */
final class FrameworkMigrationBaselineIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string BASELINE = '001_framework_baseline.sql';
    private const string DELTA = '20261023120000000000_HIL-1519_baseline_delta';
    private const string EARLIER = '20261019120000000000_HIL-1519_baseline_branch';

    private string $root;
    private string $database;
    private string $journal;
    private FrameworkMigrationProject $project;
    private FrameworkSchemaCatalog $schema;

    /** @var array<string, mixed> Migration settings whose private caches have no public snapshot/reset API */
    private array $previousMigration = [];

    /** @var array{mixed, mixed, class-string<Hilos>} Facade context and identity to restore after the case */
    private array $previousFacade;

    /** @return iterable<string, array{class-string<Hilos>}> Minimal and feature-rich real schema selections */
    public static function facadeProvider(): iterable
    {
        yield 'minimal without runtime' => [BaselineIntegrationHilos::class];
        yield 'auth i18n files analytics and journal' => [BaselineFullIntegrationHilos::class];
    }

    /** @param class-string<Hilos> $facade Metadata-only project declaration */
    #[DataProvider('facadeProvider')]
    public function testGeneratedBaselinePassesGatesAuditAndDownUp(string $facade): void
    {
        $this->selectFacade($facade);
        $this->generateBaseline();
        $this->assertSame(1, Migration::migrateUp());
        $this->assertSame([1], Migration::appliedIndices());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSelectedSchema();
        $this->assertPullIsEmpty();

        // A successful baseline down deliberately removes the selected tables with their rows.
        Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('baseline rollback fixture')");
        Database::sql("INSERT INTO `hilos_session` (`token`, `user_id`) VALUES ('baseline-session', ?)", [Database::lastInsertId()]);
        $this->assertSame(1, Migration::migrateDown(0));
        $this->assertSame([], Migration::appliedIndices());
        $this->assertNull(MigrationClaim::current());
        $this->assertSame($this->selectedTables(bootstrapOnly: true)[DatabaseConnectionDefaults::PRIMARY_INDEX],
            EntitySchemaAudit::liveTables(DatabaseConnectionDefaults::PRIMARY_INDEX));
        $this->assertSame([], EntitySchemaAudit::liveTables(ChangeLogDatabase::CONNECTION_INDEX));
        $this->assertUnapplied(self::BASELINE);

        $this->assertSame(1, Migration::migrateUp());
        $this->assertSame([1], Migration::appliedIndices());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSelectedSchema();
        Database::sql('SELECT COUNT(*) AS n FROM `hilos_user`');
        $this->assertSame(0, (int) Database::field('n'));
        Database::sql('SELECT COUNT(*) AS n FROM `hilos_session`');
        $this->assertSame(0, (int) Database::field('n'));
        $this->assertSame(0, Migration::migrateUp());
    }

    /** The existing DELIMITER guard refuses destructive down while journal history survives. */
    public function testJournalHistoryRefusesBaselineDownWithoutBypassingItsGuard(): void
    {
        $this->selectFacade(BaselineFullIntegrationHilos::class);
        $this->generateBaseline();
        $this->assertSame(1, Migration::migrateUp());
        $this->assertSelectedSchema();
        Database::sql('INSERT INTO ' . ChangeLogDatabase::identifier($this->journal) . '.`hilos_change_log_receipt` '
            . "(`created_at`, `channel`, `action`) VALUES ('2026-10-22 12:00:00', 'migration', 'baseline guard fixture')");
        $receiptId = Database::lastInsertId();
        Database::sql('INSERT INTO ' . ChangeLogDatabase::identifier($this->journal) . '.`hilos_change_log_table` (`name`) '
            . "VALUES ('hilos_user')");
        Database::sql('INSERT INTO ' . ChangeLogDatabase::identifier($this->journal) . '.`hilos_change_log` '
            . '(`created_at`, `receipt_id`, `table_id`, `record_key`, `mutation_type`) '
            . "VALUES ('2026-10-22 12:00:00', ?, ?, '1', 'create')", [$receiptId, Database::lastInsertId()]);

        try {
            Migration::migrateDown(0);
            $this->fail('The existing journal history guard must refuse baseline down');
        } catch (DatabaseException $refusal) {
            $this->assertSame(1644, $refusal->getMysqlErrorCode());
            $this->assertStringContainsString('Migration 1 rollback failed:', $refusal->getMessage());
            $this->assertStringContainsString('hilos_change_log_table is not empty', $refusal->getMessage());
        }
        // Observe the ordinary failed-DDL outcome before teardown drops only the owned databases.
        Database::sql('SELECT `failed` FROM `migration` WHERE `index` = 1');
        $this->assertSame(1, (int) Database::field('failed'));
        $this->assertSame([], Migration::appliedIndices());
        $this->assertNull(MigrationClaim::current());
        $this->assertUnapplied(self::BASELINE);
        $this->assertSelectedSchema();
        Database::sql('SELECT COUNT(*) AS n FROM ' . ChangeLogDatabase::identifier($this->journal) . '.`hilos_change_log_receipt`');
        $this->assertSame(1, (int) Database::field('n'));
        Database::sql('SELECT `receipt_id`, `record_key`, `mutation_type` FROM '
            . ChangeLogDatabase::identifier($this->journal) . '.`hilos_change_log`');
        $this->assertSame([['receipt_id' => (string) $receiptId, 'record_key' => '1', 'mutation_type' => 'create']], Database::rows());
    }

    /** Covered parts stay in the generated baseline; later changes and feature installs append once. */
    public function testPullAfterGeneratedBaselineAppendsDeltaEarlierBranchAndLateFeature(): void
    {
        $this->generateBaseline();
        $this->assertSame(1, Migration::migrateUp());
        $this->assertSelectedSchema();
        $baseline = $this->sourceSnapshot();
        $this->assertPullIsEmpty();
        Database::sql("INSERT INTO `hilos_user` (`name`) VALUES ('survives pull')");

        $this->addChange(self::DELTA, 'user', 'baseline_delta');
        $this->assertSourceRefusal('Framework migration ' . self::DELTA . '/user is missing from this project; run db:framework:pull');
        $this->pullAndApply(2, FrameworkMigrationOrigin::CHANGE, [self::DELTA . '/user']);
        $this->assertColumnExists('hilos_user', 'baseline_delta');

        // This branch's ID predates the baseline's real covered user time-zone part, as well as DELTA.
        $this->addChange(self::EARLIER, 'session', 'baseline_branch');
        $this->assertSourceRefusal('Framework migration ' . self::EARLIER . '/session is missing from this project; run db:framework:pull');
        $this->pullAndApply(3, FrameworkMigrationOrigin::CHANGE, [self::EARLIER . '/session']);
        $this->assertColumnExists('hilos_session', 'baseline_branch');

        $this->selectFacade(BaselineSettingsIntegrationHilos::class);
        $this->assertSourceRefusal('Framework migration setting is missing from this project; run db:framework:pull');
        $this->pullAndApply(4, FrameworkMigrationOrigin::INSTALL, []);
        $this->assertSame([1, 2, 3, 4], Migration::appliedIndices());
        $this->assertSelectedSchema();
        $this->assertPullIsEmpty();
        Database::sql('SELECT `name` FROM `hilos_user`');
        $this->assertSame([['name' => 'survives pull']], Database::rows());
        foreach ($baseline as $file => $body) {
            $this->assertSame($body, file_get_contents($file), 'Pull must preserve both generated baseline directions');
        }
    }

    /** Snapshot process settings, copy real sources, and provision a private empty primary/journal pair. */
    protected function setUp(): void
    {
        parent::setUp();
        FrameworkMigrationStartupGuard::resetForTests();
        foreach (['migrationListPath', 'migrationName', 'initialized'] as $property) {
            $this->previousMigration[$property] = new ReflectionProperty(Migration::class, $property)->getValue();
        }
        // No public reset exists for this connection-local bootstrap cache or the active facade identity.
        new ReflectionProperty(Migration::class, 'initialized')->setValue(null, []);
        $this->previousFacade = [Hilos::$db, Hilos::$rt, Hilos::appClass()];
        Hilos::$db = null;
        Hilos::$rt = null;
        $this->database = 'hilos-baseline-it-' . bin2hex(random_bytes(6));
        $this->journal = ChangeLogDatabase::name($this->database);
        $this->root = sys_get_temp_dir() . '/' . $this->database;
        mkdir($this->root . '/backend/Database/Migration/Schema', 0700, true);
        $this->copySourceTree(dirname(__DIR__, 2) . '/backend/Database/Migration/Change', $this->root . '/source/Change');
        mkdir($this->root . '/source/Stub');
        copy(dirname(__DIR__, 2) . '/backend/Database/Migration/catalog.json', $this->root . '/source/catalog.json');
        foreach (new FrameworkSchemaCatalog()->allUnits() as $unit) {
            if (!$unit->bootstrapOwned) {
                copy($unit->upPath, $this->root . '/source/Stub/' . basename($unit->upPath));
                copy($unit->downPath, $this->root . '/source/Stub/' . basename($unit->downPath));
            }
        }
        $this->schema = new BaselineIntegrationSchema($this->root . '/source');
        BaselineIntegrationGuard::$schema = $this->schema;
        BaselineIntegrationGuard::$sourceRoot = $this->root . '/source';
        $this->configureDatabasePair();
        Migration::setMigrationListPath($this->root . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        $this->selectFacade(BaselineIntegrationHilos::class);
    }

    /** Drop only the owned disposable pair and restore facade, startup gate and private migration settings. */
    protected function tearDown(): void
    {
        try {
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
            FrameworkMigrationStartupGuard::resetForTests();
            BaselineIntegrationGuard::$schema = null;
            BaselineIntegrationGuard::$sourceRoot = null;
            foreach ($this->previousMigration as $property => $value) {
                new ReflectionProperty(Migration::class, $property)->setValue(null, $value);
            }
            [Hilos::$db, Hilos::$rt] = $this->previousFacade;
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousFacade[2]);
            parent::tearDown();
        }
    }

    /** @param class-string<Hilos> $facade Current project declaration without initializing its features */
    private function selectFacade(string $facade): void
    {
        $this->project = new FrameworkMigrationProject($this->root . '/backend/Database/Migration/Schema', $facade);
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $facade);
    }

    /** Provision the derived journal as an empty schema; never initialize or migrate its connection. */
    private function configureDatabasePair(): void
    {
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, $primary->host, 'root',
            Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(), $primary->database, $primary->port, $primary->charset);
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        foreach ([$this->database, $this->journal] as $database) {
            Database::sql('CREATE DATABASE ' . ChangeLogDatabase::identifier($database)
                . ' ' . DatabaseConnectionDefaults::createDatabaseCharsetClause());
        }
        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX => $this->database, ChangeLogDatabase::CONNECTION_INDEX => $this->journal]
            as $index => $database) {
            Database::configure($index, $primary->host, 'root', Hilos::$env[EnvConstants::DB_ROOT_PASSWORD]->string(),
                $database, $primary->port, $primary->charset);
            Database::connect($index);
        }
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
    }

    /** Invoke the actual generator on the empty track, then prove source and applied admission are separate. */
    private function generateBaseline(): void
    {
        $this->assertSame([], $this->sourceSnapshot());
        $this->assertSame([], EntitySchemaAudit::liveTables(DatabaseConnectionDefaults::PRIMARY_INDEX));
        $this->assertSame([], EntitySchemaAudit::liveTables(ChangeLogDatabase::CONNECTION_INDEX));
        $publication = $this->installer()->executeBaseline($this->project);
        $this->assertFalse($publication->hasFailures(), $publication->projects[0]->failure?->getMessage() ?? 'Baseline publication');
        $this->assertSame([
            $this->project->root . '/001_framework_baseline_down.sql',
            $this->project->root . '/' . self::BASELINE,
        ], $publication->projects[0]->publishedPaths);
        $track = $this->installer()->readTrack($this->project, requireComplete: true);
        $this->assertCount(1, $track);
        $this->assertSame(FrameworkMigrationOrigin::BASELINE, $track[0]->origin->kind);
        $this->assertSame(1, $track[0]->index);
        $keys = array_column($this->schema->selectedUnits($this->project->facade), 'key');
        sort($keys);
        $this->assertSame($keys, $track[0]->origin->units);
        $this->assertNotEmpty($track[0]->origin->parts, 'The current user time-zone part is covered by the generated baseline');
        BaselineIntegrationGuard::prepare($this->project->facade, $this->root);
        $this->assertUnapplied(self::BASELINE);
        $this->assertSame([], Migration::appliedIndices());
        $this->assertSame([], EntitySchemaAudit::liveTables(DatabaseConnectionDefaults::PRIMARY_INDEX));
        $this->assertSame([], EntitySchemaAudit::liveTables(ChangeLogDatabase::CONNECTION_INDEX));
    }

    /** Audit exact selected physical tables, including INDEX, with separate coverage for the journal. */
    private function assertSelectedSchema(): void
    {
        $selected = $this->selectedTables();
        foreach ([DatabaseConnectionDefaults::PRIMARY_INDEX, ChangeLogDatabase::CONNECTION_INDEX] as $index) {
            $this->assertSame($index === DatabaseConnectionDefaults::PRIMARY_INDEX ? $this->database : $this->journal,
                Database::getConnectionConfig($index)->database);
            $tables = $selected[$index] ?? [];
            $live = EntitySchemaAudit::liveTables($index);
            sort($live);
            $this->assertSame($tables, $live, 'Exact tables on connection ' . $index);
            // On this code base every framework Entity belongs to PRIMARY. Journal tables have no Entity;
            // do not let an equal table name on another physical database claim one of them.
            $entities = $index === DatabaseConnectionDefaults::PRIMARY_INDEX
                ? array_values(array_filter(EntitySchemaAudit::frameworkEntities(), static fn (string $entity): bool =>
                    in_array(constant($entity . '::' . Entity::META_TABLE), $tables, true)))
                : [];
            if ($index === DatabaseConnectionDefaults::PRIMARY_INDEX) {
                $this->assertNotEmpty($entities);
            }
            $this->assertNoMismatches(EntitySchemaAudit::audit($entities, $index));
            $this->assertNoMismatches(EntitySchemaAudit::auditTableCoverage($entities,
                $index === ChangeLogDatabase::CONNECTION_INDEX ? ChangeLogTablesWithoutEntity::tables() : [], $index));
        }
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    /**
     * @param bool $bootstrapOnly Select only the tables owned by Migration initialization
     * @return array<int, list<string>> Sorted exact table sets by connection, never a union across databases
     */
    private function selectedTables(bool $bootstrapOnly = false): array
    {
        $tables = [];
        foreach ($this->schema->selectedUnits($this->project->facade) as $unit) {
            if (!$bootstrapOnly || $unit->bootstrapOwned) {
                $tables[$unit->connection] = [...($tables[$unit->connection] ?? []), ...$unit->tables];
            }
        }
        foreach ($tables as &$names) {
            sort($names);
        }

        return $tables;
    }

    /** @param list<EntitySchemaMismatch> $mismatches All audit axes, without a project-index exemption */
    private function assertNoMismatches(array $mismatches): void
    {
        $this->assertSame([], $mismatches, implode("\n", array_map(
            static fn (EntitySchemaMismatch $mismatch): string => $mismatch->describe(), $mismatches,
        )));
    }

    /** @return FrameworkMigrationInstaller Real source planner/writer reading the current private source catalog */
    private function installer(): FrameworkMigrationInstaller
    {
        return new FrameworkMigrationInstaller($this->schema, new FrameworkMigrationCatalog($this->schema, $this->root . '/source'));
    }

    /** @return array<string, string> Both SQL directions keyed by their absolute file names */
    private function sourceSnapshot(): array
    {
        $sources = [];
        foreach (glob($this->project->root . '/*.sql') as $file) {
            $sources[$file] = file_get_contents($file);
        }

        return $sources;
    }

    /** Ordinary pull must leave covered CREATE/ALTER parts and their files alone. */
    private function assertPullIsEmpty(): void
    {
        $before = $this->sourceSnapshot();
        $this->assertSame([], $this->installer()->plan($this->project)[0]->publications);
        $publication = $this->installer()->execute($this->project);
        $this->assertFalse($publication->hasFailures());
        $this->assertSame([], $publication->projects[0]->publishedPaths);
        $this->assertSame($before, $this->sourceSnapshot());
    }

    /**
     * @param int $index Next ordinary migration index
     * @param string $kind Expected change or install origin
     * @param list<string> $parts Exact newly covered catalog part identities
     */
    private function pullAndApply(int $index, string $kind, array $parts): void
    {
        $plan = $this->installer()->plan($this->project)[0];
        $this->assertCount(1, $plan->publications);
        $publication = $plan->publications[0];
        $this->assertSame($index, $publication->index);
        $this->assertSame($kind, $publication->pair->origin->kind);
        $this->assertSame($parts, array_map(static fn (FrameworkMigrationPart $part): string => $part->key(),
            $publication->pair->origin->parts));
        $outcome = $this->installer()->execute($this->project);
        $this->assertFalse($outcome->hasFailures(), $outcome->projects[0]->failure?->getMessage() ?? 'Pull publication');
        $this->assertCount(2, $outcome->projects[0]->publishedPaths);
        BaselineIntegrationGuard::prepare($this->project->facade, $this->root);
        $this->assertUnapplied(basename($publication->upPath));
        $this->assertSame(1, Migration::migrateUp());
        FrameworkMigrationStartupGuard::assertAppliedIfStarting();
        $this->assertSame(0, Migration::migrateUp());
        $this->assertPullIsEmpty();
    }

    /** @param string $file Origin up basename missing a successful primary ledger row */
    private function assertUnapplied(string $file): void
    {
        try {
            FrameworkMigrationStartupGuard::assertAppliedIfStarting();
            $this->fail('Startup accepted an unapplied framework origin');
        } catch (DatabaseException $refusal) {
            $this->assertSame("Framework migration {$file} is not applied; run db:migration:up", $refusal->getMessage());
        }
    }

    /** @param string $message Exact unit or part missing from the source coverage */
    private function assertSourceRefusal(string $message): void
    {
        try {
            BaselineIntegrationGuard::prepare($this->project->facade, $this->root);
            $this->fail('Startup accepted missing framework sources');
        } catch (DatabaseException $refusal) {
            $this->assertSame($message, $refusal->getMessage());
        }
    }

    /**
     * Evolves only the private current stub; the delta is independently executable SQL.
     *
     * @param string $id Immutable fixture change ID
     * @param string $key Selected unit receiving a nullable fixture column
     * @param string $column New column to observe after rollout
     */
    private function addChange(string $id, string $key, string $column): void
    {
        $unit = $this->schema->unit($key);
        $table = $unit->tables[0];
        $requires = array_map(static fn (FrameworkMigrationPart $part): array => [
            'changeId' => $part->changeId, 'unit' => $part->unit,
        ], new FrameworkMigrationCatalog($this->schema, $this->root . '/source')->partsForUnits([$key]));
        $up = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(32) NULL;\n";
        $down = "ALTER TABLE `{$table}` DROP COLUMN `{$column}`;\n";
        file_put_contents($unit->upPath, "\n" . $up, FILE_APPEND);
        $directory = $this->root . '/source/Change/' . $id;
        mkdir($directory);
        file_put_contents($directory . '/up.sql', $up);
        file_put_contents($directory . '/down.sql', $down);
        file_put_contents($directory . '/change.json', json_encode([
            'format' => 1, 'id' => $id,
            'parts' => [[
                'unit' => $key, 'up' => 'up.sql', 'down' => 'down.sql', 'requires' => $requires,
                'afterStubUpSha256' => hash_file('sha256', $unit->upPath),
                'afterStubDownSha256' => hash_file('sha256', $unit->downPath),
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $table Primary table changed by the fixture catalog
     * @param string $column Column absent from the original generated baseline
     */
    private function assertColumnExists(string $table, string $column): void
    {
        Database::sql('SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->database, $table, $column]);
        $this->assertSame('varchar(32)', Database::field('type'));
    }

    /**
     * @param string $source Real immutable catalog directory
     * @param string $destination Private copy, allowing new fixture parts without editing framework history
     */
    private function copySourceTree(string $source, string $destination): void
    {
        mkdir($destination, 0700, true);
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $entry) {
            $target = $destination . '/' . substr($entry->getPathname(), strlen($source) + 1);
            $entry->isDir() ? mkdir($target) : copy($entry->getPathname(), $target);
        }
    }
}

/** Real unit selection and dependency order; only the source paths point at private copies. */
final class BaselineIntegrationSchema extends FrameworkSchemaCatalog
{
    /** @param string $root Private copy of the real migration sources */
    public function __construct(private readonly string $root)
    {
        parent::__construct();
    }

    /** @return list<FrameworkSchemaUnit> Unchanged real table/connection metadata with private stub paths */
    protected function buildUnits(): array
    {
        return array_map(fn (FrameworkSchemaUnit $unit): FrameworkSchemaUnit => new FrameworkSchemaUnit(
            $unit->key,
            $unit->tables,
            $unit->upPath === null ? null : $this->root . '/Stub/' . basename($unit->upPath),
            $unit->downPath === null ? null : $this->root . '/Stub/' . basename($unit->downPath),
            $unit->dependencies,
            $unit->connection,
            $unit->bootstrapOwned,
        ), parent::buildUnits());
    }
}

/** Source factories alone are replaced; the applied gate always reads the ordinary primary Migration. */
final class BaselineIntegrationGuard extends FrameworkMigrationStartupGuard
{
    public static ?FrameworkSchemaCatalog $schema = null;
    public static ?string $sourceRoot = null;

    /** @return FrameworkSchemaCatalog Real schema metadata with temporary source paths */
    protected static function createSchemaCatalog(): FrameworkSchemaCatalog
    {
        return self::$schema;
    }

    /**
     * @param FrameworkSchemaCatalog $schema Real selected schema metadata
     * @return FrameworkMigrationCatalog Private real catalog with optional fixture additions
     */
    protected static function createMigrationCatalog(FrameworkSchemaCatalog $schema): FrameworkMigrationCatalog
    {
        return new FrameworkMigrationCatalog($schema, self::$sourceRoot);
    }
}

abstract class BaselineIntegrationHilos extends Hilos
{
    /** @return ?RtContext Minimal no-RT project, without application initialization */
    protected static function createRuntime(): ?RtContext
    {
        return null;
    }
}

abstract class BaselineFullIntegrationHilos extends BaselineIntegrationHilos
{
    protected const array FEATURES = [
        HilosFeature::AUTH, HilosFeature::I18N, HilosFeature::FILES, HilosFeature::ANALYTICS, HilosFeature::CHANGE_LOG,
    ];
}

abstract class BaselineSettingsIntegrationHilos extends BaselineIntegrationHilos
{
    protected const array FEATURES = [HilosFeature::SETTINGS];
}
