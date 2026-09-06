<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\SqlSortDirection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one place that reads the shape of an index's column list.
 *
 * The declarations are made up rather than borrowed from a framework Entity on purpose: every
 * declaration in the tree today writes its columns as bare names, so only invented ones can say
 * what the pair form means and what an unreadable one does. No database is involved - the method
 * reads a constant and nothing else.
 */
final class EntityIndexComponentsTest extends TestCase
{
    public function testABareColumnNameIsAscending(): void
    {
        $components = Entity::indexComponents([Entity::INDEX_COLUMNS => ['channel']]);

        $this->assertSame([['column' => 'channel', 'direction' => SqlSortDirection::ASC]], $components);
    }

    public function testAPairNamesItsOwnDirection(): void
    {
        $components = Entity::indexComponents([
            Entity::INDEX_COLUMNS => [
                [Entity::INDEX_COLUMN => 'created_at', Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
            ],
        ]);

        $this->assertSame([['column' => 'created_at', 'direction' => SqlSortDirection::DESC]], $components);
    }

    public function testBothFormsMixInOneDeclarationAndKeepTheirOrder(): void
    {
        $components = Entity::indexComponents([
            Entity::INDEX_COLUMNS => [
                'channel',
                [Entity::INDEX_COLUMN => 'created_at', Entity::INDEX_DIRECTION => SqlSortDirection::DESC],
                [Entity::INDEX_COLUMN => 'id', Entity::INDEX_DIRECTION => SqlSortDirection::ASC],
            ],
            Entity::INDEX_UNIQUE => true,
        ]);

        $this->assertSame([
            ['column' => 'channel', 'direction' => SqlSortDirection::ASC],
            ['column' => 'created_at', 'direction' => SqlSortDirection::DESC],
            ['column' => 'id', 'direction' => SqlSortDirection::ASC],
        ], $components);
    }

    public function testAnIndexDeclaringNoColumnsReadsAsNone(): void
    {
        $this->assertSame([], Entity::indexComponents([Entity::INDEX_UNIQUE => true]));
    }

    public function testADirectionThatIsNeitherAscendingNorDescendingIsRefusedOnTheSpot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Order direction must be ASC or DESC, got 'desc' for column 'created_at'");

        Entity::indexComponents([
            Entity::INDEX_COLUMNS => [
                [Entity::INDEX_COLUMN => 'created_at', Entity::INDEX_DIRECTION => 'desc'],
            ],
        ]);
    }

    public function testAPairMissingItsDirectionIsRefusedRatherThanReadAsAscending(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("must carry both 'column' and 'direction', got: column");

        Entity::indexComponents([
            Entity::INDEX_COLUMNS => [[Entity::INDEX_COLUMN => 'created_at']],
        ]);
    }
}
