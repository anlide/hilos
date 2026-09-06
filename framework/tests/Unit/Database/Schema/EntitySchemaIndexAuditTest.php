<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\EntitySchemaAxis;
use Hilos\Database\Schema\EntitySchemaIndexAudit;
use Hilos\Database\Schema\EntitySchemaMismatch;
use Hilos\Database\SqlSortDirection;
use Hilos\Tests\Integration\EntitySchemaConsistencyTest;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the INDEX axis of the entity schema audit.
 *
 * The live side is written by hand rather than read from a database, which is the whole reason
 * this axis was split out of {@see EntitySchemaAudit}: no table in the tree carries a descending
 * index, so the case the gate exists for is unreachable through an installation. What the real
 * entities declare is still held against the real schema by
 * {@see EntitySchemaConsistencyTest}, which this does not replace.
 */
final class EntitySchemaIndexAuditTest extends TestCase
{
    /** Named only so a finding has an entity to carry - the axis never looks at the class. */
    private const string ENTITY = Setting::class;
    private const string TABLE = 'log';

    public function testDeclaredDirectionsMatchingTheLiveOnesAreNoFinding(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            ['idx_channel_date' => [Entity::INDEX_COLUMNS => ['channel', self::descending('created_at')]]],
            [
                self::statisticsRow('idx_channel_date', 'channel', 'A'),
                self::statisticsRow('idx_channel_date', 'created_at', 'D'),
            ],
            [],
        );

        $this->assertSame([], $mismatches);
    }

    public function testALiveIndexThatLostItsDescendingColumnIsAFinding(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            ['idx_channel_date' => [Entity::INDEX_COLUMNS => ['channel', self::descending('created_at')]]],
            [
                self::statisticsRow('idx_channel_date', 'channel', 'A'),
                self::statisticsRow('idx_channel_date', 'created_at', 'A'),
            ],
            [],
        );

        $this->assertCount(1, $mismatches);
        $this->assertSame(EntitySchemaAxis::INDEX, $mismatches[0]->axis);
        $this->assertSame('idx_channel_date', $mismatches[0]->subject);
        $this->assertSame('(channel,created_at DESC)', $mismatches[0]->expected);
        $this->assertSame('(channel,created_at)', $mismatches[0]->actual);
    }

    public function testALiveIndexDescendingWhereTheDeclarationIsNotIsAFinding(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            ['idx_channel_date' => [Entity::INDEX_COLUMNS => ['channel', 'created_at']]],
            [
                self::statisticsRow('idx_channel_date', 'channel', 'A'),
                self::statisticsRow('idx_channel_date', 'created_at', 'D'),
            ],
            [],
        );

        $this->assertCount(1, $mismatches);
        $this->assertSame('(channel,created_at)', $mismatches[0]->expected);
        $this->assertSame('(channel,created_at DESC)', $mismatches[0]->actual);
    }

    public function testAColumnTheDatabaseMarksWithNoCollationReadsAsAscending(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            ['ft_message' => [Entity::INDEX_COLUMNS => ['message']]],
            [self::statisticsRow('ft_message', 'message', null)],
            [],
        );

        $this->assertSame([], $mismatches);
    }

    public function testUniquenessIsHeldAlongsideTheColumns(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            ['idx_channel' => [Entity::INDEX_COLUMNS => ['channel'], Entity::INDEX_UNIQUE => true]],
            [self::statisticsRow('idx_channel', 'channel', 'A', unique: false)],
            [],
        );

        $this->assertCount(1, $mismatches);
        $this->assertSame('unique(channel)', $mismatches[0]->expected);
        $this->assertSame('(channel)', $mismatches[0]->actual);
    }

    public function testADeclaredIndexTheDatabaseDoesNotHoldIsMissing(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            ['idx_channel_date' => [Entity::INDEX_COLUMNS => ['channel', self::descending('created_at')]]],
            [],
            [],
        );

        $this->assertCount(1, $mismatches);
        $this->assertSame('index (channel,created_at DESC)', $mismatches[0]->expected);
        $this->assertSame('missing', $mismatches[0]->actual);
    }

    public function testALiveIndexNobodyDeclaredIsAFinding(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            [],
            [self::statisticsRow('idx_stray', 'channel', 'A')],
            [],
        );

        $this->assertCount(1, $mismatches);
        $this->assertSame('declared in _indexes', $mismatches[0]->expected);
        $this->assertSame('(channel)', $mismatches[0]->actual);
    }

    public function testThePrimaryKeyAndForeignKeyIndexesTakeNoPartInThisAxis(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            [],
            [
                self::statisticsRow('PRIMARY', 'id', 'A'),
                self::statisticsRow('fk_log_channel', 'channel', 'A'),
            ],
            ['fk_log_channel'],
        );

        $this->assertSame([], $mismatches);
    }

    public function testEveryDivergenceIsReportedRatherThanTheFirst(): void
    {
        $mismatches = EntitySchemaIndexAudit::audit(
            self::ENTITY,
            self::TABLE,
            [
                'idx_channel_date' => [Entity::INDEX_COLUMNS => ['channel', self::descending('created_at')]],
                'idx_absent' => [Entity::INDEX_COLUMNS => ['level']],
            ],
            [
                self::statisticsRow('idx_channel_date', 'channel', 'A'),
                self::statisticsRow('idx_channel_date', 'created_at', 'A'),
                self::statisticsRow('idx_stray', 'message', 'A'),
            ],
            [],
        );

        $this->assertCount(3, $mismatches);
        $this->assertSame(
            ['idx_channel_date', 'idx_absent', 'idx_stray'],
            array_map(static fn (EntitySchemaMismatch $mismatch): ?string => $mismatch->subject, $mismatches),
        );
    }

    /**
     * @param string $column Column the pair names
     * @return array<string, string> The declaration of that column as descending
     */
    private static function descending(string $column): array
    {
        return [Entity::INDEX_COLUMN => $column, Entity::INDEX_DIRECTION => SqlSortDirection::DESC];
    }

    /**
     * @param string $index Index name the row belongs to
     * @param string $column Column name at this position
     * @param ?string $collation `A`, `D`, or null where the index marks no direction
     * @param bool $unique Whether the index is unique
     * @return array<string, mixed> One `information_schema.STATISTICS` row as the audit reads it
     */
    private static function statisticsRow(
        string $index,
        string $column,
        ?string $collation,
        bool $unique = false,
    ): array {
        return [
            EntitySchemaAudit::COL_INDEX_NAME => $index,
            EntitySchemaAudit::COL_NAME => $column,
            EntitySchemaAudit::COL_NON_UNIQUE => $unique ? 0 : 1,
            EntitySchemaAudit::COL_COLLATION => $collation,
        ];
    }
}
