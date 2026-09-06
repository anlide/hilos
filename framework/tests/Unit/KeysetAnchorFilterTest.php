<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\Filter\KeysetAnchorFilter;
use Hilos\Database\SqlSortDirection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the keyset condition that takes a window from an anchor.
 */
final class KeysetAnchorFilterTest extends TestCase
{
    public function testSingleColumnAscendingComparesForward(): void
    {
        $filter = new KeysetAnchorFilter(
            ['id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['id' => 42]),
            TableAnchorDirection::After,
        );

        $this->assertSame('((`id` > ?))', $filter->toSql('users'));
        $this->assertSame([42], $filter->getParams());
        $this->assertSame(['id'], $filter->getColumns());
    }

    public function testBeforeFlipsEveryComparison(): void
    {
        $filter = new KeysetAnchorFilter(
            ['id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['id' => 42]),
            TableAnchorDirection::Before,
        );

        $this->assertSame('((`id` < ? OR `id` IS NULL))', $filter->toSql('users'));
    }

    public function testChainExpandsTieBeforeEveryLaterColumn(): void
    {
        $filter = new KeysetAnchorFilter(
            ['name' => SqlSortDirection::ASC, 'id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['name' => 'Ada', 'id' => 7]),
            TableAnchorDirection::After,
        );

        $this->assertSame('((`name` > ?) OR (`name` = ? AND (`id` > ?)))', $filter->toSql('users'));
        $this->assertSame(['Ada', 'Ada', 7], $filter->getParams());
    }

    public function testMixedDirectionsCompareEachColumnItsOwnWay(): void
    {
        $filter = new KeysetAnchorFilter(
            ['name' => SqlSortDirection::DESC, 'id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['name' => 'Ada', 'id' => 7]),
            TableAnchorDirection::After,
        );

        $this->assertSame(
            '((`name` < ? OR `name` IS NULL) OR (`name` = ? AND (`id` > ?)))',
            $filter->toSql('users'),
        );
    }

    public function testAliasPrefixesEveryColumnReference(): void
    {
        $filter = new KeysetAnchorFilter(
            ['id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['id' => 42]),
            TableAnchorDirection::After,
        );

        $this->assertSame('((u.`id` > ?))', $filter->toSql('users', 'u'));
    }

    public function testNullAnchorValueAscendingKeepsEveryRowThatHasOne(): void
    {
        $filter = new KeysetAnchorFilter(
            ['title' => SqlSortDirection::ASC, 'id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['title' => null, 'id' => 7]),
            TableAnchorDirection::After,
        );

        $this->assertSame(
            '((`title` IS NOT NULL) OR (`title` IS NULL AND (`id` > ?)))',
            $filter->toSql('notes'),
        );
        $this->assertSame([7], $filter->getParams());
    }

    public function testNullAnchorValueDescendingLeavesOnlyTheTie(): void
    {
        $filter = new KeysetAnchorFilter(
            ['title' => SqlSortDirection::DESC, 'id' => SqlSortDirection::DESC],
            new TableAnchorDTO(['title' => null, 'id' => 7]),
            TableAnchorDirection::After,
        );

        $this->assertSame(
            '((0 = 1) OR (`title` IS NULL AND (`id` < ? OR `id` IS NULL)))',
            $filter->toSql('notes'),
        );
    }

    public function testColumnsTheOrderDoesNotNameAreDropped(): void
    {
        $filter = new KeysetAnchorFilter(
            ['id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['id' => 42, 'stolen`column' => 'x']),
            TableAnchorDirection::After,
        );

        $this->assertSame(['id'], $filter->getColumns());
        $this->assertSame('((`id` > ?))', $filter->toSql('users'));
    }

    public function testAnchorWithoutTheLeadingKeyColumnBuildsNoCondition(): void
    {
        $filter = new KeysetAnchorFilter(
            ['name' => SqlSortDirection::ASC, 'id' => SqlSortDirection::ASC],
            new TableAnchorDTO(['id' => 7]),
            TableAnchorDirection::After,
        );

        $this->assertSame('1=1', $filter->toSql('users'));
        $this->assertSame([], $filter->getParams());
    }
}
