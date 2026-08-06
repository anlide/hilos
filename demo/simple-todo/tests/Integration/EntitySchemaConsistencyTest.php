<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Integration;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Notification;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Schema\EntitySchemaMismatch;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration test: simple-todo demo Entity metadata against the live database
 * schema.
 *
 * Drives the reusable auditor {@see EntitySchemaAudit} over two groups of
 * entities. The demo's own entities are discovered and audited on every axis.
 * The framework entities whose tables this demo creates from its own copy of
 * the DDL are listed explicitly — a demo activates a subset of the framework
 * subsystems, so discovering them would report every table it never creates.
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

    /**
     * Framework entities whose tables this demo carries in its own migrations.
     *
     * @var list<class-string<Entity>>
     */
    private const array FRAMEWORK_ENTITIES = [
        Setting::class,
        Notification::class,
    ];

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
        foreach (self::FRAMEWORK_ENTITIES as $entityClass) {
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
     * @param class-string<Entity> $entityClass Entity under test
     * @throws DatabaseException When an introspection query fails
     */
    #[DataProvider('frameworkEntityProvider')]
    public function testFrameworkEntityMatchesSchema(string $entityClass): void
    {
        $mismatches = array_values(array_filter(
            EntitySchemaAudit::audit([$entityClass]),
            static fn(EntitySchemaMismatch $mismatch): bool => $mismatch->axis !== EntitySchemaAxis::INDEX,
        ));

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
