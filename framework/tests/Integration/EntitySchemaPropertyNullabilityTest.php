<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\PhpType;
use Hilos\Database\Schema\EntitySchemaAudit;

/**
 * Integration test: the PROPERTY_NULLABLE / PROPERTY_MISSING audit axes.
 *
 * {@see EntitySchemaConsistencyTest} proves the framework Entities are clean,
 * which by construction cannot prove the axis reports anything — a passing audit
 * over correct entities is silent whether the predicate works or not. So this test
 * drives {@see EntitySchemaAudit} over fixture Entities declared below, mapped onto
 * tables it creates itself, each column shaped to hit exactly one branch: a finding
 * in each direction, and the two skips that must stay silent.
 *
 * The fixtures live in this file rather than under backend/Database/Entity/Item on
 * purpose — that directory is what EntitySchemaConsistencyTest discovers and audits,
 * and a deliberately-wrong Entity there would fail it.
 *
 * The tables are created in setUp and dropped in tearDown, both while the base class
 * connection is open: tearDownAfterClass runs after that connection is closed and
 * would have to open a dedicated one just to drop two tables.
 */
final class EntitySchemaPropertyNullabilityTest extends FrameworkIntegrationTestCase
{
    /**
     * Creates the fixture tables (dropping first, so a killed prior run cannot collide).
     *
     * @throws DatabaseException When a fixture statement fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropFixtureTables();

        Database::sqlRun(
            'CREATE TABLE ' . PropertyNullabilityFixture::_table . ' ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            // NOT NULL with nothing to fill it: the database will reject the NULL
            // a nullable property lets through.
            . '`owner_id` INT UNSIGNED NOT NULL,'
            // NULL-able: a non-nullable property cannot hold what is stored here.
            . '`label` VARCHAR(64) NULL,'
            // NOT NULL but self-filling: a nullable property means "let the
            // database decide" and never reaches the column.
            . "`status` VARCHAR(32) NOT NULL DEFAULT 'new',"
            . 'PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );

        Database::sqlRun(
            'CREATE TABLE ' . PropertyMissingFixture::_table . ' ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`orphan` VARCHAR(32) NULL,'
            . 'PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /**
     * Drops the fixture tables while the connection is still open.
     */
    protected function tearDown(): void
    {
        try {
            $this->dropFixtureTables();
        } catch (DatabaseException) {
            // Best-effort cleanup; a failure here must not mask the test result.
        }

        parent::tearDown();
    }

    /**
     * Both directions are reported, and neither self-filling column is.
     *
     * Asserting the whole mismatch list, not just a subset, is what makes the two
     * skips (auto_increment primary key, column with a DEFAULT) part of the proof.
     *
     * @throws DatabaseException When an introspection query fails
     */
    public function testNullabilityDivergesInBothDirections(): void
    {
        $mismatches = EntitySchemaAudit::audit([PropertyNullabilityFixture::class]);

        $this->assertSame(
            [
                '[property_nullable] hilos_property_nullability_fixture.owner_id:'
                . ' expected <non-nullable property>, got <NOT NULL without default>',
                '[property_nullable] hilos_property_nullability_fixture.label:'
                . ' expected <nullable property>, got <NULL-able column>',
            ],
            array_map(static fn($mismatch): string => $mismatch->describe(), $mismatches),
        );
    }

    /**
     * A mapped column with no property to hydrate into is its own finding, and the
     * nullability verdict is not guessed on top of it.
     *
     * @throws DatabaseException When an introspection query fails
     */
    public function testMappedColumnWithoutPropertyIsReported(): void
    {
        $mismatches = EntitySchemaAudit::audit([PropertyMissingFixture::class]);

        $this->assertSame(
            [
                '[property_missing] hilos_property_missing_fixture.orphan:'
                . ' expected <instance property $orphan>, got <no property>',
            ],
            array_map(static fn($mismatch): string => $mismatch->describe(), $mismatches),
        );
    }

    /**
     * @throws DatabaseException When a drop statement fails
     */
    private function dropFixtureTables(): void
    {
        Database::sqlRun('DROP TABLE IF EXISTS ' . PropertyNullabilityFixture::_table);
        Database::sqlRun('DROP TABLE IF EXISTS ' . PropertyMissingFixture::_table);
    }
}

/**
 * Fixture Entity: one column per branch of the property-nullability predicate.
 *
 * `id` is nullable over an auto_increment primary key and `status` is nullable over
 * a NOT NULL column with a DEFAULT — both legal, both must stay silent. `owner_id`
 * and `label` are the two findings.
 */
final class PropertyNullabilityFixture extends Entity
{
    public const string _table = 'hilos_property_nullability_fixture';
    public const string _primary = 'id';
    public const array _columns = ['id', 'owner_id', 'label', 'status'];
    public const array _types = [
        'id' => PhpType::INTEGER->value,
        'owner_id' => PhpType::INTEGER->value,
        'label' => PhpType::STRING->value,
        'status' => PhpType::STRING->value,
    ];

    public ?int $id = null;
    public ?int $owner_id = null;
    public string $label = '';
    public ?string $status = null;
}

/**
 * Fixture Entity: a column declared in _columns that no property backs.
 */
final class PropertyMissingFixture extends Entity
{
    public const string _table = 'hilos_property_missing_fixture';
    public const string _primary = 'id';
    public const array _columns = ['id', 'orphan'];
    public const array _types = [
        'id' => PhpType::INTEGER->value,
        'orphan' => PhpType::STRING->value,
    ];

    public ?int $id = null;
}
