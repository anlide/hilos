<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\InMemoryTableFilter;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Tests what an in-memory window says about where it sits in its set (HIL-1093).
 *
 * The place is what the page number and the footer range are read out of, and it is the whole
 * point of the ticket: a window addressed by anchor has no page number of its own, so a client
 * counting presses of Next drifts the moment a row appears above the window. Here the set is
 * already in hand, so the place costs nothing — it is the index the slice was cut at — and the
 * cases worth pinning are the ones where there is no first row to read it off.
 */
final class InMemoryTableFilterWindowPlaceTest extends TestCase
{
    /** Payload field the rows below carry their key under. */
    private const string KEY_FIELD = 'id';

    /** Page size the windows below ask for, chosen so the set takes three pages and a bit. */
    private const int PAGE_SIZE = 3;

    /** Rows the set holds. */
    private const int ROW_COUNT = 7;

    public function testTheFirstWindowOfASetStandsAtItsStart(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(), self::KEY_FIELD);

        self::assertSame(0, $snapshot->rowsBefore);
        self::assertSame([1, 2, 3], array_column($snapshot->rows, self::KEY_FIELD));
    }

    public function testAWindowTakenAfterAnAnchorStandsBehindTheRowsUpToIt(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            $this->window(anchor: 4),
            self::KEY_FIELD,
        );

        self::assertSame(4, $snapshot->rowsBefore);
        self::assertSame([5, 6, 7], array_column($snapshot->rows, self::KEY_FIELD));
    }

    public function testAWindowTakenBackFromAnAnchorStandsWhereItsOwnFirstRowDoes(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            $this->window(anchor: 5, direction: TableAnchorDirection::Before),
            self::KEY_FIELD,
        );

        self::assertSame(1, $snapshot->rowsBefore);
        self::assertSame([2, 3, 4], array_column($snapshot->rows, self::KEY_FIELD));
    }

    public function testAnEmptyWindowAskedForPastTheEndStandsBehindTheWholeSet(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            $this->window(anchor: self::ROW_COUNT),
            self::KEY_FIELD,
        );

        self::assertSame([], $snapshot->rows);
        self::assertSame(self::ROW_COUNT, $snapshot->rowsBefore);
    }

    public function testAnEmptyWindowAskedForBeforeTheStartStandsAtTheStart(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            $this->window(anchor: 1, direction: TableAnchorDirection::Before),
            self::KEY_FIELD,
        );

        self::assertSame([], $snapshot->rows);
        self::assertSame(0, $snapshot->rowsBefore);
    }

    public function testANumberedPageStandsWhereThePagesBeforeItEnd(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(pageIndex: 2), self::KEY_FIELD);

        self::assertSame(6, $snapshot->rowsBefore);
        self::assertSame([7], array_column($snapshot->rows, self::KEY_FIELD));
    }

    public function testANumberedPagePastTheEndStillStandsWhereItWouldHaveBegun(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(pageIndex: 5), self::KEY_FIELD);

        self::assertSame([], $snapshot->rows);
        self::assertSame(15, $snapshot->rowsBefore);
    }

    public function testAWindowHoldingTheWholeSetHasNothingBeforeIt(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            new TableQueryDTO(sort: $this->order(), limit: TableConstants::NO_LIMIT),
            self::KEY_FIELD,
        );

        self::assertSame(0, $snapshot->rowsBefore);
        self::assertCount(self::ROW_COUNT, $snapshot->rows);
    }

    public function testARowInsertedAboveAStandingWindowMovesThePlaceItReportsNext(): void
    {
        $rows = $this->rows();
        array_unshift($rows, [self::KEY_FIELD => 0]);

        $snapshot = InMemoryTableFilter::apply($rows, $this->window(anchor: 4), self::KEY_FIELD);

        self::assertSame(5, $snapshot->rowsBefore);
        self::assertSame([5, 6, 7], array_column($snapshot->rows, self::KEY_FIELD));
    }

    /**
     * Builds one window query over the ordered set.
     *
     * @param ?int $anchor Row key the window is taken from, or null for the edge of the set
     * @param TableAnchorDirection $direction Side of the anchor the window is taken from
     * @param ?int $pageIndex Zero-based page to jump to, or null when the window is paged by anchor
     * @return TableQueryDTO Window query the filter is asked with
     */
    private function window(
        ?int $anchor = null,
        TableAnchorDirection $direction = TableAnchorDirection::After,
        ?int $pageIndex = null,
    ): TableQueryDTO {
        return new TableQueryDTO(
            sort: $this->order(),
            limit: self::PAGE_SIZE,
            anchor: $anchor === null ? null : new TableAnchorDTO([self::KEY_FIELD => $anchor]),
            anchorDirection: $direction,
            pageIndex: $pageIndex,
        );
    }

    /**
     * @return TableSortOrderDTO Order the whole set is read in, which is what an anchor names a place in
     */
    private function order(): TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO(self::KEY_FIELD, TableConstants::ORDER_ASC));
    }

    /**
     * @return list<array<string, mixed>> Rows of the set, keyed one through {@see self::ROW_COUNT}
     */
    private function rows(): array
    {
        $rows = [];
        for ($id = 1; $id <= self::ROW_COUNT; $id++) {
            $rows[] = [self::KEY_FIELD => $id];
        }

        return $rows;
    }
}
