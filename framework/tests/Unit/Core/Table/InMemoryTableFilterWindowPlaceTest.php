<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
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
 *
 * The same windows are held for the places framing them (HIL-1037): the side facing an anchor is
 * the anchor itself, every other side the row standing next to the window, and a side with no
 * such row is the edge of the set.
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

    public function testNullPolicyKeepsAnchorWalksNumberedPagesAndFramesInOneOrder(): void
    {
        $values = [1 => 100000, 2 => 100000, 3 => 100000, 4 => 200000, 5 => 200000, 6 => null, 7 => null, 8 => null];
        $rows = [];
        foreach ([8, 4, 2, 7, 1, 6, 5, 3] as $id) {
            $rows[] = ['nextRunAt' => $values[$id], 'id' => $id];
        }
        foreach ([false, true] as $nullsLast) {
            foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
                $keys = $direction === TableConstants::ORDER_ASC
                    ? [1, 2, 3, 4, 5, 6, 7, 8]
                    : ($nullsLast ? [5, 4, 3, 2, 1, 8, 7, 6] : [8, 7, 6, 5, 4, 3, 2, 1]);
                $order = TableSortOrderDTO::of(new TableSortDTO('nextRunAt', $direction));
                $places = array_map(static fn(int $id): array => ['nextRunAt' => $values[$id], 'id' => $id], $keys);
                $forward = [];
                $backward = [];
                $after = null;
                $before = null;
                for ($page = 0; $page < 4; $page++) {
                    $numbered = InMemoryTableFilter::apply($rows, new TableQueryDTO(
                        sort: $order, limit: 2, pageIndex: $page,
                    ), self::KEY_FIELD, $nullsLast);
                    $next = InMemoryTableFilter::apply($rows, new TableQueryDTO(
                        sort: $order, limit: 2, anchor: $after,
                    ), self::KEY_FIELD, $nullsLast);
                    $previous = InMemoryTableFilter::apply($rows, new TableQueryDTO(
                        sort: $order, limit: 2, anchor: $before, anchorDirection: TableAnchorDirection::Before,
                    ), self::KEY_FIELD, $nullsLast);
                    $start = $page * 2;
                    $backStart = 6 - $start;
                    self::assertSame(array_slice($places, $start, 2), $numbered->rows);
                    self::assertSame($numbered->rows, $next->rows);
                    self::assertSame(array_slice($places, $backStart, 2), $previous->rows);
                    self::assertSame($start, $numbered->rowsBefore);
                    self::assertSame($start, $next->rowsBefore);
                    self::assertSame($backStart, $previous->rowsBefore);
                    foreach ([$numbered, $next] as $snapshot) {
                        self::assertSame($places[$start], $snapshot->firstAnchor?->values);
                        self::assertSame($places[$start + 1], $snapshot->lastAnchor?->values);
                        self::assertNotNull($snapshot->frame);
                        self::assertSame($places[$start - 1] ?? null, $snapshot->frame->before?->values);
                        self::assertSame($places[$start + 2] ?? null, $snapshot->frame->after?->values);
                    }
                    self::assertSame($places[$backStart], $previous->firstAnchor?->values);
                    self::assertSame($places[$backStart + 1], $previous->lastAnchor?->values);
                    self::assertNotNull($previous->frame);
                    self::assertSame($places[$backStart - 1] ?? null, $previous->frame->before?->values);
                    self::assertSame($places[$backStart + 2] ?? null, $previous->frame->after?->values);
                    array_push($forward, ...array_column($next->rows, 'id'));
                    $backward = [...array_column($previous->rows, 'id'), ...$backward];
                    $after = $next->lastAnchor;
                    $before = $previous->firstAnchor;
                }
                self::assertSame($keys, $forward);
                self::assertSame($keys, $backward);
                self::assertSame([], InMemoryTableFilter::apply($rows, new TableQueryDTO(
                    sort: $order, limit: 2, anchor: $after,
                ), self::KEY_FIELD, $nullsLast)->rows);
                self::assertSame([], InMemoryTableFilter::apply($rows, new TableQueryDTO(
                    sort: $order, limit: 2, anchor: $before, anchorDirection: TableAnchorDirection::Before,
                ), self::KEY_FIELD, $nullsLast)->rows);
            }
        }
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

    public function testTheFirstWindowOfASetIsFramedByTheStartAndTheRowAfterIt(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(), self::KEY_FIELD);

        $this->assertFrame(null, 4, $snapshot->frame);
    }

    public function testAWindowTakenAfterAnAnchorIsFramedBeforeByThatAnchor(): void
    {
        $query = $this->window(anchor: 2);

        $snapshot = InMemoryTableFilter::apply($this->rows(), $query, self::KEY_FIELD);

        self::assertSame([3, 4, 5], array_column($snapshot->rows, self::KEY_FIELD));
        self::assertNotNull($snapshot->frame);
        self::assertSame($query->anchor, $snapshot->frame->before);
        self::assertSame([self::KEY_FIELD => 6], $snapshot->frame->after?->values);
    }

    public function testAWindowTakenBackFromAnAnchorIsFramedAfterByThatAnchor(): void
    {
        $query = $this->window(anchor: 5, direction: TableAnchorDirection::Before);

        $snapshot = InMemoryTableFilter::apply($this->rows(), $query, self::KEY_FIELD);

        self::assertNotNull($snapshot->frame);
        self::assertSame([self::KEY_FIELD => 1], $snapshot->frame->before?->values);
        self::assertSame($query->anchor, $snapshot->frame->after);
    }

    public function testANumberedPageInTheMiddleIsFramedByTheRowsOnEitherSide(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(pageIndex: 1), self::KEY_FIELD);

        self::assertSame([4, 5, 6], array_column($snapshot->rows, self::KEY_FIELD));
        $this->assertFrame(3, 7, $snapshot->frame);
    }

    public function testTheFirstNumberedPageHasNothingBeforeIt(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(pageIndex: 0), self::KEY_FIELD);

        $this->assertFrame(null, 4, $snapshot->frame);
    }

    public function testTheLastNumberedPageHasNothingAfterIt(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(pageIndex: 2), self::KEY_FIELD);

        $this->assertFrame(6, null, $snapshot->frame);
    }

    public function testAWindowHoldingTheWholeSetIsFramedByBothEdges(): void
    {
        $snapshot = InMemoryTableFilter::apply(
            $this->rows(),
            new TableQueryDTO(sort: $this->order(), limit: TableConstants::NO_LIMIT),
            self::KEY_FIELD,
        );

        $this->assertFrame(null, null, $snapshot->frame);
    }

    public function testAnEmptyWindowPastTheEndReportsNoFrame(): void
    {
        $snapshot = InMemoryTableFilter::apply($this->rows(), $this->window(pageIndex: 5), self::KEY_FIELD);

        self::assertNull($snapshot->frame);
    }

    /**
     * Asserts the places a frame holds, by the key of the row each one stands at.
     *
     * @param ?int $before Key standing right before the window, or null for the start of the set
     * @param ?int $after Key standing right after the window, or null for the end of the set
     * @param ?TableWindowFrameDTO $frame Frame the snapshot carries
     */
    private function assertFrame(?int $before, ?int $after, ?TableWindowFrameDTO $frame): void
    {
        self::assertNotNull($frame);
        self::assertSame($before === null ? null : [self::KEY_FIELD => $before], $frame->before?->values);
        self::assertSame($after === null ? null : [self::KEY_FIELD => $after], $frame->after?->values);
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
