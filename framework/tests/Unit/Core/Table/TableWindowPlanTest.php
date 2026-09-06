<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Table;

use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableWindowPlan;
use Hilos\Database\SqlSortDirection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for how a numbered page is placed when the count behind it is not exact.
 *
 * The anchored window is not exercised here: it never reads the total, so the ceiling cannot
 * reach it. What the ceiling does reach is the two readings a numbered page is built out of —
 * which end of the set is nearer, and where the set ends — and both of those are what these
 * tests hold.
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
        $this->assertSame(0, $plan->offset);
        $this->assertSame(self::PAGE_SIZE, $plan->limit);
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
        $this->assertSame(9 * self::PAGE_SIZE, $plan->offset);
        $this->assertSame(self::PAGE_SIZE, $plan->limit);
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
        $this->assertSame(400 * self::PAGE_SIZE, $plan->offset);
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
