<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableWindowFrameDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Database\SqlSortDirection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how a window is planned and how what the query returned is cut into it.
 *
 * A numbered page is built out of two readings of the count — which end of the set is nearer,
 * and where the set ends — and a count stopped at its ceiling reaches both. Every path also
 * takes the places framing the window, so the skip and the take asserted here are the ones
 * the SQL runs, the frame rows included; the cut then has to hand back exactly the window, in
 * the set's own order, with the frame beside it.
 */
final class TableWindowPlanTest extends TestCase
{
    private const array ORDER = ['id' => SqlSortDirection::ASC];

    private const int PAGE_SIZE = 20;

    public function testAnExactCountCountsTheFarHalfFromTheNearerEnd(): void
    {
        $plan = TableWindowPlan::forQuery($this->pageQuery(9), self::ORDER, 200, true);

        $this->assertNotNull($plan);
        $this->assertTrue($plan->reversed);
        // The last page: nothing stands after it, so only the row before it is taken on top.
        $this->assertSame(0, $plan->offset);
        $this->assertSame(self::PAGE_SIZE + 1, $plan->limit);
        $this->assertSame(self::PAGE_SIZE, $plan->window);
        $this->assertFalse($plan->leadingFrame);
        $this->assertNull($plan->nearFrame);
    }

    public function testAPageCountedFromTheEndTakesTheRowAfterItFirst(): void
    {
        // Page 6 of 200 rows is rows 120-139: 60 rows stand after it, one of them frames it.
        $plan = TableWindowPlan::forQuery($this->pageQuery(6), self::ORDER, 200, true);

        $this->assertNotNull($plan);
        $this->assertTrue($plan->reversed);
        $this->assertSame(59, $plan->offset);
        $this->assertSame(self::PAGE_SIZE + 2, $plan->limit);
        $this->assertSame(self::PAGE_SIZE, $plan->window);
        $this->assertTrue($plan->leadingFrame);
        $this->assertNull($plan->nearFrame);
    }

    public function testAPageCountedFromTheStartStepsOneRowBackForItsFrame(): void
    {
        $plan = TableWindowPlan::forQuery($this->pageQuery(2), self::ORDER, 200, true);

        $this->assertNotNull($plan);
        $this->assertFalse($plan->reversed);
        $this->assertSame(2 * self::PAGE_SIZE - 1, $plan->offset);
        $this->assertSame(self::PAGE_SIZE + 2, $plan->limit);
        $this->assertSame(self::PAGE_SIZE, $plan->window);
        $this->assertTrue($plan->leadingFrame);
        $this->assertNull($plan->nearFrame);
    }

    public function testTheFirstPageDoesNotStepBackPastTheStartOfTheSet(): void
    {
        $plan = TableWindowPlan::forQuery($this->pageQuery(0), self::ORDER, 200, true);

        $this->assertNotNull($plan);
        $this->assertSame(0, $plan->offset);
        $this->assertSame(self::PAGE_SIZE + 1, $plan->limit);
        $this->assertFalse($plan->leadingFrame);
    }

    public function testAnAnchoredWindowIsFramedOnItsNearSideByTheAnchor(): void
    {
        $anchor = new TableAnchorDTO(['id' => 40]);
        $plan = TableWindowPlan::forQuery(
            new TableQueryDTO(limit: self::PAGE_SIZE, anchor: $anchor, anchorDirection: TableAnchorDirection::Before),
            self::ORDER,
            200,
            true,
        );

        $this->assertNotNull($plan);
        $this->assertTrue($plan->reversed);
        $this->assertSame(0, $plan->offset);
        $this->assertSame(self::PAGE_SIZE + 1, $plan->limit);
        $this->assertSame(self::PAGE_SIZE, $plan->window);
        $this->assertFalse($plan->leadingFrame);
        $this->assertSame($anchor, $plan->nearFrame);
    }

    public function testAWindowWithoutALimitTakesNoFrameRows(): void
    {
        $plan = TableWindowPlan::forQuery(new TableQueryDTO(), self::ORDER, 200, true);

        $this->assertNotNull($plan);
        $this->assertSame(0, $plan->offset);
        $this->assertSame(TableConstants::NO_LIMIT, $plan->limit);
        $this->assertSame(TableConstants::NO_LIMIT, $plan->window);
        $this->assertFalse($plan->leadingFrame);
        $this->assertNull($plan->nearFrame);
    }

    public function testAnExactCountRefusesAPageThatLiesPastTheEndOfTheSet(): void
    {
        $this->assertNull(TableWindowPlan::forQuery($this->pageQuery(9), self::ORDER, 42, true));
    }

    public function testACountStoppedAtItsCeilingSkipsFromTheStartInstead(): void
    {
        $plan = TableWindowPlan::forQuery(
            $this->pageQuery(9),
            self::ORDER,
            TableConstants::COUNT_CEILING,
            false,
        );

        $this->assertNotNull($plan);
        $this->assertFalse($plan->reversed);
        $this->assertSame(9 * self::PAGE_SIZE - 1, $plan->offset);
        $this->assertSame(self::PAGE_SIZE + 2, $plan->limit);
        $this->assertSame(self::PAGE_SIZE, $plan->window);
        $this->assertTrue($plan->leadingFrame);
        $this->assertSame(self::ORDER, $plan->orderBy);
    }

    public function testACountStoppedAtItsCeilingRefusesNoPageAtAll(): void
    {
        // The page starts far past the ceiling, which under an exact count would be past the end
        // of the set. There is no end to be past here, so the window is run and comes back empty.
        $plan = TableWindowPlan::forQuery(
            $this->pageQuery(400),
            self::ORDER,
            TableConstants::COUNT_CEILING,
            false,
        );

        $this->assertNotNull($plan);
        $this->assertSame(400 * self::PAGE_SIZE - 1, $plan->offset);
    }

    public function testCuttingAPageInTheSetsOwnOrderTakesItsFrameFromBothEnds(): void
    {
        $plan = $this->plannedPage(2, 5);

        // Rows 11-15 of 100: the query steps back to 10 and takes one past the page, 16.
        [$rows, $frame] = $plan->cut($this->rows(10, 16), $this->placeOf(...));

        $this->assertSame([11, 12, 13, 14, 15], $this->ids($rows));
        $this->assertFrame(10, 16, $frame);
    }

    public function testCuttingAPageCountedFromTheEndTurnsItBackAndSwapsItsFrame(): void
    {
        // Rows 11-15 of 20, counted from the end: the query returns 16 first, then 15 down to 10.
        $plan = $this->plannedPage(2, 5, 20);

        [$rows, $frame] = $plan->cut($this->rows(16, 10), $this->placeOf(...));

        $this->assertSame([11, 12, 13, 14, 15], $this->ids($rows));
        $this->assertFrame(10, 16, $frame);
    }

    public function testCuttingTheFirstPageLeavesNothingBeforeIt(): void
    {
        $plan = $this->plannedPage(0, 5);

        [$rows, $frame] = $plan->cut($this->rows(1, 6), $this->placeOf(...));

        $this->assertSame([1, 2, 3, 4, 5], $this->ids($rows));
        $this->assertFrame(null, 6, $frame);
    }

    public function testCuttingTheLastPageLeavesNothingAfterIt(): void
    {
        // Rows 16-20 of 20, counted from the end: nothing stands after them, 15 frames them.
        $plan = $this->plannedPage(3, 5, 20);

        [$rows, $frame] = $plan->cut($this->rows(20, 15), $this->placeOf(...));

        $this->assertSame([16, 17, 18, 19, 20], $this->ids($rows));
        $this->assertFrame(15, null, $frame);
    }

    public function testCuttingAWindowAfterAnAnchorIsFramedBeforeByTheAnchor(): void
    {
        $anchor = new TableAnchorDTO(['id' => 3]);
        $plan = $this->plannedAnchor($anchor, TableAnchorDirection::After);

        [$rows, $frame] = $plan->cut($this->rows(4, 9), $this->placeOf(...));

        $this->assertSame([4, 5, 6, 7, 8], $this->ids($rows));
        $this->assertNotNull($frame);
        $this->assertSame($anchor, $frame->before);
        $this->assertSame(['id' => 9], $frame->after?->values);
    }

    public function testCuttingAWindowBeforeAnAnchorIsFramedAfterByTheAnchor(): void
    {
        $anchor = new TableAnchorDTO(['id' => 9]);
        $plan = $this->plannedAnchor($anchor, TableAnchorDirection::Before);

        [$rows, $frame] = $plan->cut($this->rows(8, 3), $this->placeOf(...));

        $this->assertSame([4, 5, 6, 7, 8], $this->ids($rows));
        $this->assertNotNull($frame);
        $this->assertSame(['id' => 3], $frame->before?->values);
        $this->assertSame($anchor, $frame->after);
    }

    public function testCuttingAWindowTheSetEndsInsideHasNothingOnItsFarSide(): void
    {
        $plan = $this->plannedAnchor(new TableAnchorDTO(['id' => 3]), TableAnchorDirection::After);

        [$rows, $frame] = $plan->cut($this->rows(4, 6), $this->placeOf(...));

        $this->assertSame([4, 5, 6], $this->ids($rows));
        $this->assertNotNull($frame);
        $this->assertNull($frame->after);
    }

    public function testCuttingAnEmptyWindowReportsNoFrame(): void
    {
        $this->assertSame([[], null], $this->plannedPage(2, 5)->cut([], $this->placeOf(...)));
        $this->assertSame([[], null], $this->plannedPage(2, 5)->cut($this->rows(10, 10), $this->placeOf(...)));
        $this->assertSame(
            [[], null],
            $this->plannedAnchor(null, TableAnchorDirection::After)->cut([], $this->placeOf(...)),
        );
    }

    public function testCuttingAWindowWithoutALimitFramesItWithTheEdgesOfTheSet(): void
    {
        $plan = TableWindowPlan::forQuery(new TableQueryDTO(), self::ORDER, 3, true);
        $this->assertNotNull($plan);

        [$rows, $frame] = $plan->cut($this->rows(1, 3), $this->placeOf(...));

        $this->assertSame([1, 2, 3], $this->ids($rows));
        $this->assertFrame(null, null, $frame);
    }

    public function testCuttingKeepsTheKeysOfTheRows(): void
    {
        $plan = $this->plannedPage(1, 2, 20);
        $fetched = ['a' => ['id' => 2], 'b' => ['id' => 3], 'c' => ['id' => 4], 'd' => ['id' => 5]];

        [$rows] = $plan->cut($fetched, $this->placeOf(...));

        $this->assertSame(['b' => ['id' => 3], 'c' => ['id' => 4]], $rows);
    }

    /**
     * Plans one numbered page against an exact count.
     *
     * @param int $pageIndex Zero-based page the window jumps to
     * @param int $pageSize Rows on a page
     * @param int $totalCount Rows in the set
     * @return TableWindowPlan Plan the page is run by
     */
    private function plannedPage(int $pageIndex, int $pageSize, int $totalCount = 100): TableWindowPlan
    {
        $plan = TableWindowPlan::forQuery(
            new TableQueryDTO(limit: $pageSize, pageIndex: $pageIndex),
            self::ORDER,
            $totalCount,
            true,
        );
        $this->assertNotNull($plan);

        return $plan;
    }

    /**
     * Plans a window of five rows taken from an anchor.
     *
     * @param ?TableAnchorDTO $anchor Place the window is taken from, or null for the edge of the set
     * @param TableAnchorDirection $direction Side of the anchor the window lies on
     * @return TableWindowPlan Plan the window is run by
     */
    private function plannedAnchor(?TableAnchorDTO $anchor, TableAnchorDirection $direction): TableWindowPlan
    {
        $plan = TableWindowPlan::forQuery(
            new TableQueryDTO(limit: 5, anchor: $anchor, anchorDirection: $direction),
            self::ORDER,
            100,
            true,
        );
        $this->assertNotNull($plan);

        return $plan;
    }

    /**
     * Builds the rows a query returns, running from one id to another in either direction.
     *
     * @param int $from Id of the first row returned
     * @param int $to Id of the last row returned
     * @return list<array{id: int}> Rows in the order the query returned them
     */
    private function rows(int $from, int $to): array
    {
        return array_map(static fn(int $id): array => ['id' => $id], range($from, $to));
    }

    /**
     * Reads the place a test row sits at.
     *
     * @param array{id: int} $row Row the query returned
     * @return TableAnchorDTO Place of that row in the id order
     */
    private function placeOf(array $row): TableAnchorDTO
    {
        return TableAnchorDTO::fromRow($row, ['id']);
    }

    /**
     * Reads the ids of cut rows in the order they came out.
     *
     * @param array<int|string, array{id: int}> $rows Window rows
     * @return list<int> Their ids
     */
    private function ids(array $rows): array
    {
        return array_values(array_map(static fn(array $row): int => $row['id'], $rows));
    }

    /**
     * Asserts the places a frame holds, by the id each one stands at.
     *
     * @param ?int $before Id standing right before the window, or null for the start of the set
     * @param ?int $after Id standing right after the window, or null for the end of the set
     * @param ?TableWindowFrameDTO $frame Frame the cut reported
     */
    private function assertFrame(?int $before, ?int $after, ?TableWindowFrameDTO $frame): void
    {
        $this->assertNotNull($frame);
        $this->assertSame($before === null ? null : ['id' => $before], $frame->before?->values);
        $this->assertSame($after === null ? null : ['id' => $after], $frame->after?->values);
    }

    /**
     * Builds a query jumping to one numbered page of the standard size.
     *
     * @param int $pageIndex Zero-based page the window jumps to
     * @return TableQueryDTO Window query addressed by that page number
     */
    private function pageQuery(int $pageIndex): TableQueryDTO
    {
        return new TableQueryDTO(limit: self::PAGE_SIZE, pageIndex: $pageIndex);
    }
}
