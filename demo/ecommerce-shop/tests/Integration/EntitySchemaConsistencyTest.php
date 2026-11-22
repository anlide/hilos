<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Hilos;
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
use Hilos\Database\Schema\MomentColumns;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration test: ecommerce-shop demo Entity metadata against the live
 * database schema.
 *
 * Drives the reusable auditor {@see EntitySchemaAudit} in both directions.
 *
 * Entity to schema: the demo's own entities and every framework entity are
 * discovered and audited axis by axis, one test case each. A framework entity
 * whose table this demo does not create is skipped — a demo activates a subset of the
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
    /** PSR-4 namespace of the demo's own Entity directory. */
    private const string OWN_ENTITY_NAMESPACE = 'Demo\\EcommerceShop\\Database\\Entity\\Item\\';

    /**
     * Lower bound so an empty discovery cannot masquerade as "no drift": the warehouse, the
     * category and its address, the product and its address, the stock, the guest's cart and the
     * cart of an account, and Voucher.
     */
    private const int MIN_OWN_ENTITY_COUNT = 9;

    /** Lower bound so an unmigrated database cannot masquerade as "no drift" either. */
    private const int MIN_TABLE_COUNT = 6;

    /**
     * @return iterable<string, array{class-string<Entity>}> Case name => [entity class]
     */
    public static function ownEntityProvider(): iterable
    {
        foreach (EntitySchemaAudit::discoverEntities(self::ownEntityDir(), self::OWN_ENTITY_NAMESPACE) as $entityClass) {
            yield self::shortName($entityClass) => [$entityClass];
        }
    }

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
     * Discovery must find at least the known demo entities, so a broken scan
     * cannot silently turn the audit into a no-op.
     */
    public function testDiscoveryFindsDemoEntities(): void
    {
        $entities = EntitySchemaAudit::discoverEntities(self::ownEntityDir(), self::OWN_ENTITY_NAMESPACE);

        $this->assertGreaterThanOrEqual(
            self::MIN_OWN_ENTITY_COUNT,
            count($entities),
            'Entity discovery found fewer classes than expected; PSR-4 mapping or directory changed',
        );
    }

    /**
     * A demo-owned Entity must match its table on every audit axis.
     *
     * @param class-string<Entity> $entityClass Entity under test
     * @throws DatabaseException When an introspection query fails
     */
    #[DataProvider('ownEntityProvider')]
    public function testOwnEntityMatchesSchema(string $entityClass): void
    {
        $mismatches = EntitySchemaAudit::audit([$entityClass]);

        $this->assertSame([], $mismatches, self::describeAll($mismatches));
    }

    /**
     * A framework Entity must match the demo's copy of its table on every axis
     * but INDEX: the project may add an index of its own for its own queries.
     * It may extend the schema, not diverge from the metadata.
     *
     * An entity of a subsystem this demo never activated has no table here, and
     * is skipped rather than failed. The skip lives in the case and not in the
     * provider because PHPUnit builds providers before setUp, with no database
     * to ask yet.
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
        $schemaIndex = $entityClass::getDatabase()->schemaIndex;
        $liveTables = EntitySchemaAudit::liveTables($schemaIndex);
        if ($schemaIndex === 0 && !in_array($table, $liveTables, true)) {
            $this->markTestSkipped("{$table} is not created by this demo");
        }
        $this->assertContains($table, $liveTables, "{$table} must exist in its declared schema {$schemaIndex}");

        $mismatches = array_values(array_filter(
            EntitySchemaAudit::audit([EntitySchemaAudit::mountedClassOf($entityClass)]),
            static fn(EntitySchemaMismatch $mismatch): bool => $mismatch->axis !== EntitySchemaAxis::INDEX,
        ));

        $this->assertSame([], $mismatches, self::describeAll($mismatches));
    }

    /**
     * Require every physical moment to match the storage norm on each declared schema.
     *
     * @param array<int, class-string<TablesWithoutEntityProvider>> $additionalProviders Test-only project metadata per connection
     */
    #[DataProvider('momentProviderRegistrations')]
    public function testLiveMomentsMatchStorageNorm(array $additionalProviders): void
    {
        $ownEntities = EntitySchemaAudit::discoverEntities(self::ownEntityDir(), self::OWN_ENTITY_NAMESPACE);
        self::assertGreaterThanOrEqual(self::MIN_OWN_ENTITY_COUNT, count($ownEntities));
        $frameworkEntities = EntitySchemaAudit::frameworkEntities();
        self::assertNotEmpty($frameworkEntities, 'Framework Entity discovery must not be empty');
        $entities = array_values(array_unique([
            ...$ownEntities,
            ...array_map(EntitySchemaAudit::mountedClassOf(...), $frameworkEntities),
        ]));

        $tablesByIndex = [];
        $mismatchesByIndex = [];
        foreach (Database::getConfiguredIndices() as $index) {
            $tablesByIndex[$index] = EntitySchemaAudit::liveTables($index);
            $mismatchesByIndex[$index] = [];
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
            $mismatchesByIndex[$index] ??= [];
            if ($index === DatabaseConnectionDefaults::PRIMARY_INDEX
                && !in_array($entityClass, $ownEntities, true)
                && !in_array($entityClass::_table, $tablesByIndex[$index], true)) {
                continue;
            }
            self::assertContains($entityClass::_table, $tablesByIndex[$index], "{$entityClass} must exist on schema {$index}");
            array_push($mismatchesByIndex[$index], ...EntitySchemaAudit::auditMoments([$entityClass]));
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
                foreach (MomentColumns::forProvider($providerClass) as $table => $moments) {
                    if ($providerClass !== FrameworkTablesWithoutEntity::class && $moments !== []) {
                        self::assertContains((string) $table, $tablesByIndex[$index], "Own moment table {$table} on schema {$index}");
                    }
                }
                array_push($mismatchesByIndex[$index], ...EntitySchemaAudit::auditProviderMoments($providerClass, $index));
            }
        }

        foreach ($mismatchesByIndex as $index => $mismatches) {
            self::assertSame([], $mismatches, "Schema {$index}: " . self::describeAll($mismatches));
        }
    }

    /**
     * Every table of the live schema must be claimed: by one of the entities this
     * demo audits, or by the framework's declaration that it has no entity at all.
     * A table nobody claims is the finding this direction exists to make.
     *
     * The demo passes no allowance of its own — every table it migrates for itself
     * has an Entity behind it.
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

        $mismatches = EntitySchemaAudit::auditTableCoverage([
            ...EntitySchemaAudit::discoverEntities(self::ownEntityDir(), self::OWN_ENTITY_NAMESPACE),
            ...EntitySchemaAudit::frameworkEntities(),
        ]);

        $this->assertSame([], $mismatches, self::describeAll($mismatches));
    }

    /**
     * @return string Absolute path of the demo's own Entity directory
     */
    private static function ownEntityDir(): string
    {
        return __DIR__ . '/../../backend/Database/Entity/Item';
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
