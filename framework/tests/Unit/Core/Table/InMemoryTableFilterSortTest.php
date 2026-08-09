<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Tests that a window carrying no sort and no search is left exactly as the table produced it.
 *
 * "No ordering" reaches the filter as a null sort rather than as an empty field name, so the
 * filter no longer has to recognize the empty string as a request for arrival order (HIL-544).
 */
final class InMemoryTableFilterSortTest extends TestCase
{
    public function testAWindowWithoutASortKeepsArrivalOrder(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), new TableQueryDTO());

        self::assertSame(['b', 'a', 'c'], array_column($snapshot->rows, 'name'));
        self::assertSame(3, $snapshot->totalCount);
    }

    public function testASortOrdersByItsOwnFieldAndDirection(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            new TableQueryDTO(sort: new TableSortDTO('name', TableConstants::ORDER_DESC)),
        );

        self::assertSame(['c', 'b', 'a'], array_column($snapshot->rows, 'name'));
    }

    public function testAWindowWithoutASearchKeepsEveryRow(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), new TableQueryDTO(search: null));

        self::assertCount(3, $snapshot->rows);
    }

    /**
     * @return list<array<string, mixed>> Rows in the order the table produced them
     */
    private function rows(): array
    {
        return [['name' => 'b'], ['name' => 'a'], ['name' => 'c']];
    }
}
