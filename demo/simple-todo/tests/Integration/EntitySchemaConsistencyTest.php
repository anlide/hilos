<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Integration;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Schema\EntitySchemaMismatch;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration test: simple-todo demo Entity metadata against the live database
 * schema.
 *
 * Drives the reusable auditor {@see EntitySchemaAudit} in both directions.
 *
 * Entity to schema: the demo's own entities and every framework entity are
 * discovered and audited axis by axis, one test case each. A framework entity
 * whose table this demo does not create is skipped — a demo activates a subset
 * of the framework subsystems, and carries the DDL of that subset only.
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
    private const string OWN_ENTITY_NAMESPACE = 'Demo\\SimpleTodo\\Database\\Entity\\Item\\';

    /** Lower bound so an empty discovery cannot masquerade as "no drift". */
    private const int MIN_OWN_ENTITY_COUNT = 2;

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
     * @param class-string<Entity> $entityClass Entity under test
     * @throws DatabaseException When an introspection query fails
     */
    #[DataProvider('frameworkEntityProvider')]
    public function testFrameworkEntityMatchesSchema(string $entityClass): void
    {
        $table = (string) constant("{$entityClass}::" . Entity::META_TABLE);
        if (!in_array($table, EntitySchemaAudit::liveTables(), true)) {
            $this->markTestSkipped("{$table} is not created by this demo");
        }

        $mismatches = array_values(array_filter(
            EntitySchemaAudit::audit([$entityClass]),
            static fn(EntitySchemaMismatch $mismatch): bool => $mismatch->axis !== EntitySchemaAxis::INDEX,
        ));

        $this->assertSame([], $mismatches, self::describeAll($mismatches));
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
