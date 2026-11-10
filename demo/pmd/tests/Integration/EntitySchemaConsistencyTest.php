<?php

declare(strict_types=1);

namespace Demo\Pmd\Tests\Integration;

use Composer\InstalledVersions;
use Demo\Pmd\Hilos;
use Hilos\Backup\BackupConstants;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\MigrationClaim;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Schema\EntitySchemaMismatch;
use Hilos\Database\Schema\FrameworkTablesWithoutEntity;
use Hilos\Database\Schema\MomentColumn;
use Hilos\Database\Schema\MomentColumns;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Testing\MomentStorageBaseline;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration test: framework Entity metadata against the PMD
 * demo's live database schema.
 *
 * Drives the reusable auditor {@see EntitySchemaAudit} in both directions.
 *
 * Entity to schema: every framework entity is audited axis by axis, one test
 * case each; the demo has no entity of its own. A framework entity whose table
 * this demo does not create is skipped — a demo activates a subset of the
 * framework subsystems, and carries the DDL of that subset only.
 *
 * Schema to Entity: one case demands the opposite of every live table, that some
 * audited entity map it or that it be declared to have none.
 *
 * Runs on top of the test database raised by composer run test:db-reset; it
 * neither creates nor drops schema and only reads information_schema.
 */
final class EntitySchemaConsistencyTest extends IntegrationTestCase
{
    /** Lower bound so an unmigrated database cannot masquerade as "no drift" either. */
    private const int MIN_TABLE_COUNT = 6;

    /** Shared registry belongs to this monorepo's tests, not to the framework library. */
    private const string MOMENT_BASELINE = '/framework/tests/CodeStyle/moment-storage-baseline.json';

    /**
     * @return iterable<string, array{class-string<Entity>}> Case name => [entity class]
     */
    public static function frameworkEntityProvider(): iterable
    {
        foreach (EntitySchemaAudit::frameworkEntities() as $entityClass) {
            yield self::shortName($entityClass) => [$entityClass];
        }
    }

    /**
     * @return iterable<string, array{array<int, class-string<TablesWithoutEntityProvider>>}> Extra project providers per connection
     */
    public static function momentProviderRegistrations(): iterable
    {
        yield 'configured providers' => [[]];
        yield 'framework moment also declared by project' => [[
            DatabaseConnectionDefaults::PRIMARY_INDEX => MigrationClaimMomentProvider::class,
        ]];
    }

    /**
     * A framework Entity must match the demo's copy of its table on every axis
     * but INDEX: the project may add an index of its own for its own queries.
     * It may extend the schema, not diverge from the metadata.
     *
     * An entity outside the selected framework schema units is skipped before
     * resolving its database; an inactive subsystem may have no connection.
     * Every table selected by the project must exist in its declared schema.
     *
     * A framework Entity the demo extends is audited as the class mounted over its
     * table, so the demo's own columns on that table are the mounted class's columns
     * and not a divergence of the base's.
     *
     * @param class-string<Entity> $entityClass Entity under test
     * @throws DatabaseException When an introspection query fails
     */
    #[DataProvider('frameworkEntityProvider')]
    public function testFrameworkEntityMatchesSchema(string $entityClass): void
    {
        $table = (string) constant("{$entityClass}::" . Entity::META_TABLE);
        if (!in_array($entityClass, self::selectedFrameworkEntities(), true)) {
            $this->markTestSkipped("{$table} is not selected by this demo");
        }
        $schemaIndex = $entityClass::getDatabase()->schemaIndex;
        $liveTables = EntitySchemaAudit::liveTables($schemaIndex);
        $this->assertContains($table, $liveTables, "{$table} must exist in its declared schema {$schemaIndex}");

        $mismatches = array_values(array_filter(
            EntitySchemaAudit::audit([EntitySchemaAudit::mountedClassOf($entityClass)]),
            static fn(EntitySchemaMismatch $mismatch): bool => $mismatch->axis !== EntitySchemaAxis::INDEX,
        ));

        $this->assertSame([], $mismatches, self::describeAll($mismatches));
    }

    /**
     * Reconcile physical moment findings against the shared historical registry on every schema.
     * Framework debt is checked once per physical schema with the same canonical subjects.
     *
     * @param array<int, class-string<TablesWithoutEntityProvider>> $additionalProviders Test-only project metadata per connection
     */
    #[DataProvider('momentProviderRegistrations')]
    public function testLiveMomentsMatchStorageBaseline(array $additionalProviders): void
    {
        $baselinePath = InstalledVersions::getInstallPath('anlide/hilos') . self::MOMENT_BASELINE;
        self::assertFileIsReadable($baselinePath);
        $json = file_get_contents($baselinePath);
        self::assertIsString($json, 'The monorepo moment storage baseline must be readable');
        $frameworkEntities = EntitySchemaAudit::frameworkEntities();
        self::assertNotEmpty($frameworkEntities, 'Framework Entity discovery must not be empty');
        $entities = array_values(array_unique(array_map(EntitySchemaAudit::mountedClassOf(...), self::selectedFrameworkEntities())));

        $tablesByIndex = [];
        $subjectsByIndex = [];
        $reportedByIndex = [];
        foreach (Database::getConfiguredIndices() as $index) {
            $tablesByIndex[$index] = EntitySchemaAudit::liveTables($index);
            $subjectsByIndex[$index] = [];
            $reportedByIndex[$index] = [];
        }
        self::assertGreaterThanOrEqual(self::MIN_TABLE_COUNT, count($tablesByIndex[DatabaseConnectionDefaults::PRIMARY_INDEX]));
        // Activated raw tables are required too; provider audits alone skip absent tables.
        foreach (new FrameworkSchemaCatalog()->selectedUnits(Hilos::appClass()) as $unit) {
            foreach ($unit->tables as $table) {
                self::assertContains($table, $tablesByIndex[$unit->connection], "Required {$table} on schema {$unit->connection}");
            }
        }

        foreach ($entities as $entityClass) {
            $index = $entityClass::getDatabase()->schemaIndex;
            $tablesByIndex[$index] ??= EntitySchemaAudit::liveTables($index);
            $subjectsByIndex[$index] ??= [];
            $reportedByIndex[$index] ??= [];
            if ($index === DatabaseConnectionDefaults::PRIMARY_INDEX
                && !in_array($entityClass::_table, $tablesByIndex[$index], true)) {
                continue;
            }
            self::assertContains($entityClass::_table, $tablesByIndex[$index], "{$entityClass} must exist on schema {$index}");
            $subjects = [];
            foreach (MomentColumns::forEntity($entityClass) as $moment) {
                $subjects[$entityClass::_table][$moment->column] = self::momentSubject(
                    $entityClass,
                    $entityClass::_table,
                    $moment,
                    $frameworkEntities,
                );
                $subjectsByIndex[$index][] = $subjects[$entityClass::_table][$moment->column];
            }
            self::appendMomentFindings($reportedByIndex[$index], EntitySchemaAudit::auditMoments([$entityClass]), $subjects);
        }

        $providers = array_fill_keys(Database::getConfiguredIndices(), [FrameworkTablesWithoutEntity::class]);
        $catalogClass = Hilos::getBackupCatalogClass();
        $declaredProviders = $catalogClass === null ? [] : ($catalogClass::getCatalog()[BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY] ?? []);
        self::assertIsArray($declaredProviders, 'Project raw providers must be declared per connection');
        foreach ([$declaredProviders, $additionalProviders] as $providerDeclarations) {
            foreach ($providerDeclarations as $index => $providerClass) {
                self::assertIsInt($index);
                self::assertIsString($providerClass);
                self::assertTrue(is_a($providerClass, TablesWithoutEntityProvider::class, true));
                self::assertArrayHasKey($index, $providers, 'A project raw provider must name a configured schema');
                $providers[$index][] = $providerClass;
            }
        }
        foreach ($providers as $index => $providerClasses) {
            foreach (array_unique($providerClasses) as $providerClass) {
                $subjects = [];
                foreach (MomentColumns::forProvider($providerClass) as $table => $moments) {
                    if ($providerClass !== FrameworkTablesWithoutEntity::class && $moments !== []) {
                        self::assertContains((string) $table, $tablesByIndex[$index], "Own moment table {$table} on schema {$index}");
                    }
                    if (!in_array((string) $table, $tablesByIndex[$index], true)) {
                        continue;
                    }
                    foreach ($moments as $moment) {
                        $subjects[$table][$moment->column] = self::momentSubject(
                            $providerClass,
                            (string) $table,
                            $moment,
                            $frameworkEntities,
                        );
                        $subjectsByIndex[$index][] = $subjects[$table][$moment->column];
                    }
                }
                self::appendMomentFindings(
                    $reportedByIndex[$index],
                    EntitySchemaAudit::auditProviderMoments($providerClass, $index),
                    $subjects,
                );
            }
        }

        foreach ($reportedByIndex as $index => $reported) {
            $problems = MomentStorageBaseline::fromJson($json)->reconcile(
                $reported,
                array_values(array_unique($subjectsByIndex[$index])),
            );
            self::assertSame([], $problems, "Schema {$index}: " . implode(PHP_EOL, $problems));
        }
    }

    /**
     * Every table of the live schema must be claimed: by one of the framework
     * entities, or by the framework's declaration that it has no entity at all.
     * A table nobody claims is the finding this direction exists to make.
     *
     * The demo passes no allowance of its own — it migrates no table for itself.
     *
     * @throws DatabaseException When an introspection query fails
     */
    public function testEveryTableIsCoveredByAnEntity(): void
    {
        $tables = EntitySchemaAudit::liveTables();

        $this->assertGreaterThanOrEqual(
            self::MIN_TABLE_COUNT,
            count($tables),
            'live schema holds fewer tables than expected; the database is not migrated',
        );

        $mismatches = EntitySchemaAudit::auditTableCoverage(self::selectedFrameworkEntities());

        $this->assertSame([], $mismatches, self::describeAll($mismatches));
    }

    /**
     * @return list<class-string<Entity>> Framework Entities whose tables belong to the project's selected units
     */
    private static function selectedFrameworkEntities(): array
    {
        $tables = [];
        foreach (new FrameworkSchemaCatalog()->selectedUnits(Hilos::appClass()) as $unit) {
            $tables = [...$tables, ...$unit->tables];
        }

        return array_values(array_filter(
            EntitySchemaAudit::frameworkEntities(),
            static fn(string $entityClass): bool => in_array($entityClass::_table, $tables, true),
        ));
    }

    /**
     * @param class-string<Entity> $entityClass Entity class name
     * @return string Short class name, used as the test case label
     */
    private static function shortName(string $entityClass): string
    {
        return substr((string) strrchr($entityClass, '\\'), 1);
    }

    /**
     * @param list<EntitySchemaMismatch> $mismatches Divergences to report
     * @return string One mismatch per line, for the assertion message
     */
    private static function describeAll(array $mismatches): string
    {
        return implode(PHP_EOL, array_map(
            static fn(EntitySchemaMismatch $mismatch): string => $mismatch->describe(),
            $mismatches,
        ));
    }

    /**
     * @param class-string<Entity>|class-string<TablesWithoutEntityProvider> $declaration Audited Entity or provider
     * @param string $table Physical table name
     * @param MomentColumn $moment Validated semantic descriptor, including unmapped moments
     * @param list<class-string<Entity>> $frameworkEntities Canonical framework catalog
     * @return string Canonical schema subject, preserving the framework owner through project mounts
     */
    private static function momentSubject(string $declaration, string $table, MomentColumn $moment, array $frameworkEntities): string
    {
        if ($moment->frameworkOwned) {
            if (is_a($declaration, Entity::class, true)) {
                $owners = array_values(array_filter($frameworkEntities, static fn(string $entityClass): bool =>
                    is_a($declaration, $entityClass, true) && in_array($moment->column, $entityClass::_moments, true)));
                self::assertCount(1, $owners, 'A framework moment must have one canonical Entity owner');
                $declaration = $owners[0];
            } else {
                $declaration = FrameworkTablesWithoutEntity::class;
            }
        }
        return self::momentJson(['schema', $declaration, $table, $moment->column]);
    }

    /**
     * Missing columns and non-moment axes fail before baseline reconciliation.
     * Repeated declarations share one physical finding within this schema.
     *
     * @param array<string, list<string>> $reported Canonical physical finding keys with one diagnostic occurrence each for one schema
     * @param list<EntitySchemaMismatch> $mismatches Live storage findings
     * @param array<string, array<string, string>> $subjects Subjects keyed by physical table and column
     */
    private static function appendMomentFindings(array &$reported, array $mismatches, array $subjects): void
    {
        foreach ($mismatches as $mismatch) {
            $rule = match ($mismatch->axis) {
                EntitySchemaAxis::MOMENT_TYPE => 'MOMENT-TYPE',
                EntitySchemaAxis::MOMENT_AUTO_WRITE => 'MOMENT-AUTO-WRITE',
                default => self::fail($mismatch->describe()),
            };
            self::assertNotNull($mismatch->subject);
            self::assertArrayHasKey($mismatch->table, $subjects);
            self::assertArrayHasKey($mismatch->subject, $subjects[$mismatch->table]);
            $key = self::momentJson([$rule, $subjects[$mismatch->table][$mismatch->subject], $mismatch->actual]);
            $reported[$key] ??= [$mismatch->describe()];
        }
    }

    /**
     * @param array $tuple Infrastructure subject or finding tuple
     * @return string Canonical baseline JSON without escaped Unicode or slashes
     */
    private static function momentJson(array $tuple): string
    {
        return json_encode($tuple, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

/** Project metadata may repeat an existing framework moment without creating another physical column. */
final class MigrationClaimMomentProvider implements TablesWithoutEntityProvider
{
    /** @return list<string> Existing framework table included in project metadata */
    public static function tables(): array
    {
        return [MigrationClaim::TABLE];
    }

    /** @return array<string, list<string>> Framework moment preserved by the project declaration */
    public static function moments(): array
    {
        return [MigrationClaim::TABLE => ['claimed_at']];
    }

    /** @return array<string, array<string, string>> No project storage override */
    public static function momentStorage(): array
    {
        return [];
    }

    /** @return array<string, array> No personal columns on the migration claim */
    public static function pii(): array
    {
        return [MigrationClaim::TABLE => []];
    }

    /** @return array<string, list<string>> Complete non-personal verdict for the existing table */
    public static function piiNotPersonal(): array
    {
        return [MigrationClaim::TABLE => FrameworkTablesWithoutEntity::piiNotPersonal()[MigrationClaim::TABLE]];
    }
}
