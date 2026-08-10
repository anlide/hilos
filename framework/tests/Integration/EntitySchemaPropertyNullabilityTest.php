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
 * in each direction, a finding over a column the database has a DEFAULT for, and the
 * two shapes that must stay silent.
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
            // NOT NULL with a DEFAULT: the property's null still leaves as an
            // explicit NULL, so the DEFAULT never gets to fill it.
            . "`status` VARCHAR(32) NOT NULL DEFAULT 'new',"
            // Generated, and therefore NULL-able whether we like it or not:
            // MariaDB rejects a NULL / NOT NULL attribute on a generated column,
            // so IS_NULLABLE stays YES and a nullable property is the right one.
            // The expression may not name the auto_increment column, hence owner_id.
            . "`slug` VARCHAR(64) GENERATED ALWAYS AS (CONCAT('row-', `owner_id`)) STORED,"
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
     * Both directions are reported, a DEFAULT does not excuse a nullable property,
     * and the two columns that must stay silent do.
     *
     * Asserting the whole mismatch list, not just a subset, is what makes those
     * skips part of the proof. The columns carrying the rule of HIL-518 are
     * `status` (a DEFAULT is not a second owner of the value, so the nullable
     * property is a finding) against `id`, where `saveInsert()` does omit a null
     * primary key, and `slug`, which MariaDB reports NULL-able because a generated
     * column may carry no nullability attribute at all.
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
                '[property_nullable] hilos_property_nullability_fixture.status:'
                . ' expected <non-nullable property>,'
                . ' got <NOT NULL with a DEFAULT an explicit NULL bypasses>',
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
 * `id` (auto_increment primary key) and `slug` (generated, and so NULL-able on this
 * engine) are the legal shapes that must stay silent. `owner_id`, `label` and
 * `status` are the findings: a plain NOT NULL column, a NULL-able one, and a NOT
 * NULL column with a DEFAULT the explicit NULL of `saveInsert()` would bypass.
 */
final class PropertyNullabilityFixture extends Entity
{
    public const string _table = 'hilos_property_nullability_fixture';
    public const string _primary = 'id';
    public const array _columns = ['id', 'owner_id', 'label', 'status', 'slug'];
    public const array _types = [
        'id' => PhpType::INTEGER->value,
        'owner_id' => PhpType::INTEGER->value,
        'label' => PhpType::STRING->value,
        'status' => PhpType::STRING->value,
        'slug' => PhpType::STRING->value,
    ];

    public ?int $id = null;
    public ?int $owner_id = null;
    public string $label = '';
    public ?string $status = null;
    public ?string $slug = null;
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
