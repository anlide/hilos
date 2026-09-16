<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Router;

use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what a viewport descriptor knows about the edges of its set, and what it still knows once its count has
 * stopped at a ceiling.
 */
final class TableViewportSubscriptionTest extends TestCase
{
    private const int PAGE_SIZE = 20;

    public function testAWindowStartsOutTrustingItsCount(): void
    {
        $this->assertTrue(new TableViewportSubscription(tableKey: 'deliveries')->totalExact());
    }

    public function testTheLastNumberedPageOfAnExactCountReachesTheEnd(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE, pageIndex: 2);
        $viewport->recordWindow(self::windowOf(['a', 'b']), 42, true, null, null);

        $this->assertTrue($viewport->reachesEnd());
    }

    public function testANumberedPageOfACountStoppedAtItsCeilingReachesNoEnd(): void
    {
        // The same window under the same numbers, told only that the count stopped early: the end
        // of the set is not at the ceiling, so answering yes off it would end every larger set at 500.
        $viewport = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE, pageIndex: 24);
        $viewport->recordWindow(self::windowOf(['a', 'b']), TableConstants::COUNT_CEILING, false, null, null);

        $this->assertFalse($viewport->totalExact());
        $this->assertFalse($viewport->reachesEnd());
    }

    public function testAnAnchoredWindowStillReadsItsOwnSizeWhenTheCountStoppedEarly(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE);
        $viewport->recordWindow(self::windowOf(['a', 'b']), TableConstants::COUNT_CEILING, false, null, null);

        $this->assertTrue($viewport->reachesEnd());
    }

    public function testAWindowWithNoLimitReachesTheStart(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'deliveries');
        $viewport->recordWindow(self::windowOf(['a', 'b']), 2, true, null, null);

        $this->assertTrue($viewport->reachesStart());
    }

    public function testOnlyTheFirstNumberedPageReachesTheStart(): void
    {
        // Page zero is the start however far the count got, so a count stopped at its ceiling changes nothing here.
        $first = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE, pageIndex: 0);
        $first->recordWindow(self::windowOf(['a', 'b']), TableConstants::COUNT_CEILING, false, null, null);
        $second = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE, pageIndex: 1);
        $second->recordWindow(self::windowOf(['a', 'b']), 42, true, null, null);

        $this->assertTrue($first->reachesStart());
        $this->assertFalse($second->reachesStart());
    }

    public function testAWindowPagedBackReachesTheStartOnlyWhenItRanShort(): void
    {
        $short = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: self::PAGE_SIZE,
            anchor: new TableAnchorDTO(['id' => 7]),
            anchorDirection: TableAnchorDirection::Before,
        );
        $short->recordWindow(self::windowOf(['a', 'b']), 42, true, null, null);
        $full = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: 2,
            anchor: new TableAnchorDTO(['id' => 7]),
            anchorDirection: TableAnchorDirection::Before,
        );
        $full->recordWindow(self::windowOf(['a', 'b']), 42, true, null, null);

        $this->assertTrue($short->reachesStart());
        $this->assertFalse($full->reachesStart());
    }

    public function testAWindowAskedForwardReachesTheStartOnlyFromTheEdgeOfTheSet(): void
    {
        $fromEdge = new TableViewportSubscription(tableKey: 'deliveries', limit: 2);
        $fromEdge->recordWindow(self::windowOf(['a', 'b']), 42, true, null, null);
        $fromAnchor = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE, anchor: new TableAnchorDTO(['id' => 7]));
        $fromAnchor->recordWindow(self::windowOf(['a', 'b']), 42, true, null, null);

        $this->assertTrue($fromEdge->reachesStart());
        $this->assertFalse($fromAnchor->reachesStart());
    }

    public function testRecordingANewTotalCarriesTheWordOnItAlong(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE);
        $viewport->recordWindow(self::windowOf(['a']), 3, true, null, null);
        $viewport->recordTotal(TableConstants::COUNT_CEILING, false);

        $this->assertSame(TableConstants::COUNT_CEILING, $viewport->totalCount());
        $this->assertFalse($viewport->totalExact());
    }

    /**
     * Builds a delivered window of placeholder rows under the given keys.
     *
     * @param list<string> $rowIds Row-id keys the window delivered, in display order
     * @return array<string, array{rowKey: int|string, slots: array<string, mixed>}> Window of placeholder rows
     */
    private static function windowOf(array $rowIds): array
    {
        $window = [];
        foreach ($rowIds as $rowId) {
            $window[$rowId] = [PagePayload::rowKey => $rowId, PagePayload::slots => []];
        }

        return $window;
    }
}
