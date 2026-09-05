<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Tests what the in-memory filter does with the ordering a window asked for.
 *
 * "No ordering" reaches the filter as a null sort rather than as an empty field name, so the
 * filter no longer has to recognize the empty string as a request for arrival order (HIL-544).
 * An ordering that was asked for is settled by the row key, or a column with repeats would let
 * two neighbouring pages show one row twice and another not at all (HIL-786).
 */
final class InMemoryTableFilterSortTest extends TestCase
{
    /** Payload field the rows below carry their key under. */
    private const string KEY_FIELD = 'id';

    /** Payload field the repeated-value rows are sorted by. */
    private const string STATE_FIELD = 'state';

    /** Page size the page walks use, chosen so the six rows take three pages. */
    private const int PAGE_SIZE = 2;

    public function testAWindowWithoutASortKeepsArrivalOrder(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), new TableQueryDTO(), self::KEY_FIELD);

        self::assertSame(['b', 'a', 'c'], array_column($snapshot->rows, 'name'));
        self::assertSame(3, $snapshot->totalCount);
    }

    public function testASortOrdersByItsOwnFieldAndDirection(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            new TableQueryDTO(sort: new TableSortDTO('name', TableConstants::ORDER_DESC)),
            self::KEY_FIELD,
        );

        self::assertSame(['c', 'b', 'a'], array_column($snapshot->rows, 'name'));
    }

    public function testAWindowWithoutASearchKeepsEveryRow(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), new TableQueryDTO(search: null), self::KEY_FIELD);

        self::assertCount(3, $snapshot->rows);
    }

    public function testWalkingEveryPageOfARepeatedColumnShowsEachRowExactlyOnce(): void
    {
        $keys = $this->walkPages(new TableSortDTO(self::STATE_FIELD, TableConstants::ORDER_ASC));

        self::assertSame([2, 4, 6, 1, 3, 5], $keys);
    }

    public function testTheTieBreakerFollowsTheDirectionOfTheSortedColumn(): void
    {
        $keys = $this->walkPages(new TableSortDTO(self::STATE_FIELD, TableConstants::ORDER_DESC));

        self::assertSame([5, 3, 1, 6, 4, 2], $keys);
    }

    /**
     * A row with no key cannot be told apart from its equals, and that is all it costs: it stays
     * where the stable sort leaves it instead of jumping ahead of rows it ties with.
     */
    public function testARowWithoutTheKeyFieldKeepsItsPlace(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            [
                [self::KEY_FIELD => 1, self::STATE_FIELD => 'live'],
                [self::STATE_FIELD => 'live'],
                [self::KEY_FIELD => 2, self::STATE_FIELD => 'idle'],
            ],
            new TableQueryDTO(sort: new TableSortDTO(self::STATE_FIELD, TableConstants::ORDER_ASC)),
            self::KEY_FIELD,
        );

        $keys = array_map(static fn(array $row): mixed => $row[self::KEY_FIELD] ?? null, $snapshot->rows);

        self::assertSame([2, 1, null], $keys);
    }

    /**
     * Walks every page of the repeated-value set and collects the keys in the order they arrived.
     *
     * @param TableSortDTO $sort Ordering to ask each page for
     * @return list<int> Row keys across all pages, in the order the pages delivered them
     */
    private function walkPages(TableSortDTO $sort): array
    {
        $rows = $this->repeatedRows();

        $keys = [];
        for ($offset = 0; $offset < count($rows); $offset += self::PAGE_SIZE) {
            $snapshot = $this->page($rows, $sort, $offset);
            foreach ($snapshot->rows as $row) {
                $keys[] = (int) $row[self::KEY_FIELD];
            }
        }

        return $keys;
    }

    /**
     * Asks for one page of the given rows, the way a window would.
     *
     * @param list<array<string, mixed>> $rows Rows the table produced
     * @param TableSortDTO $sort Ordering the window asked for
     * @param int $offset Zero-based offset of the page
     * @return TableSnapshotDTO Snapshot of that one page
     */
    private function page(array $rows, TableSortDTO $sort, int $offset): TableSnapshotDTO
    {
        return InMemoryTableFilter::apply(
            $rows,
            new TableQueryDTO(sort: $sort, offset: $offset, limit: self::PAGE_SIZE),
            self::KEY_FIELD,
        );
    }

    /**
     * @return list<array<string, mixed>> Rows in the order the table produced them
     */
    private function rows(): array
    {
        return [
            [self::KEY_FIELD => 1, 'name' => 'b'],
            [self::KEY_FIELD => 2, 'name' => 'a'],
            [self::KEY_FIELD => 3, 'name' => 'c'],
        ];
    }

    /**
     * Six rows over two repeated states, interleaved so ordering by state alone has to move rows.
     *
     * @return list<array<string, mixed>> Rows in the order the table produced them
     */
    private function repeatedRows(): array
    {
        return [
            [self::KEY_FIELD => 1, self::STATE_FIELD => 'live'],
            [self::KEY_FIELD => 2, self::STATE_FIELD => 'idle'],
            [self::KEY_FIELD => 3, self::STATE_FIELD => 'live'],
            [self::KEY_FIELD => 4, self::STATE_FIELD => 'idle'],
            [self::KEY_FIELD => 5, self::STATE_FIELD => 'live'],
            [self::KEY_FIELD => 6, self::STATE_FIELD => 'idle'],
        ];
    }
}
