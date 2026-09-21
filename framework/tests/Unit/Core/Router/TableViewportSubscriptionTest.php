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
 * stopped at a ceiling, and for the two ways it compares a delivered row: whole, and cut down to what the tab draws.
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

    public function testRemovingARowFromAFullAnchoredWindowDoesNotMakeItReachTheEnd(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: 3,
            anchor: new TableAnchorDTO(['id' => 7]),
        );
        $viewport->recordWindow(self::windowOf(['a', 'b', 'c']), 42, true, null, null);

        $this->assertFalse($viewport->reachesEnd());

        $viewport->forgetRow('a');

        $this->assertFalse($viewport->reachesEnd());
    }

    public function testRemovingARowFromAFullWindowPagedBackDoesNotMakeItReachTheStart(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: 3,
            anchor: new TableAnchorDTO(['id' => 7]),
            anchorDirection: TableAnchorDirection::Before,
        );
        $viewport->recordWindow(self::windowOf(['a', 'b', 'c']), 42, true, null, null);

        $this->assertFalse($viewport->reachesStart());

        $viewport->forgetRow('a');

        $this->assertFalse($viewport->reachesStart());
    }

    public function testAppendingToAShortAnchoredWindowDoesNotTakeAwayTheEnd(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: 3,
            anchor: new TableAnchorDTO(['id' => 7]),
        );
        $viewport->recordWindow(self::windowOf(['a', 'b']), 2, true, null, null);

        $this->assertTrue($viewport->reachesEnd());

        $viewport->recordRow('c', self::windowOf(['c'])['c']);

        $this->assertTrue($viewport->reachesEnd());
    }

    public function testRemovingFromAShortAnchoredWindowDoesNotTakeAwayTheEnd(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: 3,
            anchor: new TableAnchorDTO(['id' => 7]),
        );
        $viewport->recordWindow(self::windowOf(['a', 'b']), 2, true, null, null);

        $this->assertTrue($viewport->reachesEnd());

        $viewport->forgetRow('a');

        $this->assertTrue($viewport->reachesEnd());
    }

    public function testRebuildingAWindowReplacesWhetherItReachedTheEnd(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: 3,
            anchor: new TableAnchorDTO(['id' => 7]),
        );
        $viewport->recordWindow(self::windowOf(['a', 'b']), 2, true, null, null);

        $this->assertTrue($viewport->reachesEnd());

        $viewport->recordWindow(self::windowOf(['a', 'b', 'c']), 42, true, null, null);

        $this->assertFalse($viewport->reachesEnd());
    }

    public function testDeclaringRenderedFieldsCarriesWhetherTheWindowReachedTheEnd(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: 'deliveries',
            limit: 3,
            anchor: new TableAnchorDTO(['id' => 7]),
        );
        $viewport->recordWindow(self::windowOf(['a', 'b', 'c']), 42, true, null, null);
        $viewport->forgetRow('a');

        $declared = $viewport->withRendered(['label'], []);

        $this->assertFalse($declared->reachesEnd());
    }

    public function testANumberedPageReadsItsEndFromTheLiveRowsAndTotal(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'deliveries', limit: 3, pageIndex: 2);
        $viewport->recordWindow(self::windowOf(['a', 'b', 'c']), 9, true, null, null);

        $this->assertTrue($viewport->reachesEnd());

        $viewport->recordRow('d', self::windowOf(['d'])['d']);
        $viewport->recordTotal(10, true);

        $this->assertTrue($viewport->reachesEnd());
    }

    public function testRecordingANewTotalCarriesTheWordOnItAlong(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'deliveries', limit: self::PAGE_SIZE);
        $viewport->recordWindow(self::windowOf(['a']), 3, true, null, null);
        $viewport->recordTotal(TableConstants::COUNT_CEILING, false);

        $this->assertSame(TableConstants::COUNT_CEILING, $viewport->totalCount());
        $this->assertFalse($viewport->totalExact());
    }

    public function testAWindowDeclaringNoDrawnFieldsComparesTheRowWhole(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'users');
        $viewport->recordWindow(['7' => self::userRow(false, 'Ann')], 1, true, null, null);

        $this->assertTrue($viewport->matchesRenderedRow('7', self::userRow(false, 'Ann')));
        $this->assertFalse($viewport->matchesRenderedRow('7', self::userRow(true, 'Ann')));
    }

    public function testARowThatChangedOnlyInAnUndrawnFieldDrawsTheSame(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'users', rendered: ['id', 'name', 'presence']);
        $viewport->recordWindow(['7' => self::userRow(false, 'Ann')], 1, true, null, null);

        // The whole row did change, and that answer is untouched: only the drawn part matches.
        $this->assertFalse($viewport->matchesRow('7', self::userRow(true, 'Ann')));
        $this->assertTrue($viewport->matchesRenderedRow('7', self::userRow(true, 'Ann')));
    }

    public function testARowThatChangedInADrawnFieldDoesNotDrawTheSame(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'users', rendered: ['id', 'name', 'presence']);
        $viewport->recordWindow(['7' => self::userRow(false, 'Ann')], 1, true, null, null);

        $this->assertFalse($viewport->matchesRenderedRow('7', self::userRow(false, 'Anna')));
    }

    public function testAFieldDrawnFromAnySlotIsComparedInEverySlot(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'users', rendered: ['id', 'name', 'presence']);
        $viewport->recordWindow(['7' => self::userRow(false, 'Ann')], 1, true, null, null);

        $this->assertFalse($viewport->matchesRenderedRow('7', self::userRow(false, 'Ann', 'offline')));
    }

    public function testTheOrderTheFieldsAreDeclaredInDoesNotMatter(): void
    {
        // Declared in reverse of the order the row carries them: the cut keeps the row's order, so
        // a column moved in the declaration does not read as a change of every row.
        $declared = new TableViewportSubscription(tableKey: 'users', rendered: ['presence', 'name', 'id']);
        $declared->recordRow('7', self::userRow(false, 'Ann'));

        $this->assertTrue($declared->matchesRenderedRow('7', self::userRow(true, 'Ann')));
    }

    public function testASlotThatIsNotARecordIsComparedWhole(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'users', rendered: ['id']);
        $viewport->recordRow('7', [PagePayload::rowKey => '7', PagePayload::slots => ['tags' => ['a', 'b']]]);

        // A list names no fields, so there is nothing to cut it down to.
        $this->assertFalse($viewport->matchesRenderedRow('7', [PagePayload::rowKey => '7', PagePayload::slots => ['tags' => ['a']]]));
    }

    public function testAForgottenRowNeverDrawsTheSame(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'users', rendered: ['id', 'name']);
        $viewport->recordRow('7', self::userRow(false, 'Ann'));
        $viewport->forgetRow('7');

        $this->assertFalse($viewport->matchesRenderedRow('7', self::userRow(false, 'Ann')));
    }

    public function testAWindowServedBeforeItsFieldsWereDeclaredLearnsThemAfterwards(): void
    {
        // The cold entry: served from the table's declaration, before the tab could say what it draws.
        $cold = new TableViewportSubscription(tableKey: 'users', limit: 10);
        $cold->recordWindow(['7' => self::userRow(false, 'Ann')], 1, true, null, null);

        $declared = $cold->withRendered(['id', 'name', 'presence'], ['7' => self::userRow(false, 'Ann')]);

        $this->assertSame(['id', 'name', 'presence'], $declared->rendered);
        $this->assertTrue($declared->matchesRenderedRow('7', self::userRow(true, 'Ann')));
        $this->assertFalse($declared->matchesRenderedRow('7', self::userRow(true, 'Anna')));
    }

    public function testTheDeclaredWindowIsStillTheWindowThatWasDelivered(): void
    {
        $first = new TableAnchorDTO(['name' => 'Ann', 'id' => 7]);
        $last = new TableAnchorDTO(['name' => 'Bob', 'id' => 8]);
        $cold = new TableViewportSubscription(tableKey: 'users', filter: ['search' => 'a'], limit: 2, pageIndex: 3);
        $cold->recordWindow(
            ['7' => self::userRow(false, 'Ann'), '8' => self::userRow(false, 'Bob')],
            40,
            false,
            $first,
            $last,
            ['7' => $first, '8' => $last],
        );

        $declared = $cold->withRendered(['name'], []);

        $this->assertSame(['search' => 'a'], $declared->filter);
        $this->assertSame(2, $declared->limit);
        $this->assertSame(3, $declared->pageIndex);
        $this->assertSame(['7', '8'], $declared->rowIds());
        $this->assertSame(['7' => $first, '8' => $last], $declared->rowAnchors());
        $this->assertSame(40, $declared->totalCount());
        $this->assertFalse($declared->totalExact());
        $this->assertSame($first, $declared->firstAnchor());
        $this->assertSame($last, $declared->lastAnchor());
        $this->assertTrue($declared->matchesRow('7', self::userRow(false, 'Ann')));
    }

    public function testARowThatChangedBeforeTheFieldsArrivedIsStillComparedWhole(): void
    {
        $cold = new TableViewportSubscription(tableKey: 'users', limit: 10);
        $cold->recordWindow(['7' => self::userRow(false, 'Ann')], 1, true, null, null);

        // Read again after a rename the connection has not been told of yet. Taking the drawn part
        // off that read would record "Anna" as delivered, and the rename's own delta would then be
        // silenced as a change of nothing drawn.
        $declared = $cold->withRendered(['id', 'name', 'presence'], ['7' => self::userRow(false, 'Anna')]);

        $this->assertFalse($declared->matchesRenderedRow('7', self::userRow(true, 'Anna')));
        $this->assertFalse($declared->matchesRenderedRow('7', self::userRow(true, 'Ann')));
    }

    public function testARowTheSecondReadDidNotReturnIsStillComparedWhole(): void
    {
        $cold = new TableViewportSubscription(tableKey: 'users', limit: 10);
        $cold->recordWindow(['7' => self::userRow(false, 'Ann')], 1, true, null, null);

        $declared = $cold->withRendered(['id', 'name', 'presence'], []);

        $this->assertTrue($declared->hasRow('7'));
        $this->assertFalse($declared->matchesRenderedRow('7', self::userRow(true, 'Ann')));
    }

    public function testARowDeliveredAfterTheDeclarationIsComparedByWhatIsDrawn(): void
    {
        $declared = new TableViewportSubscription(tableKey: 'users', limit: 10)->withRendered(['id', 'name', 'presence'], []);
        $declared->recordRow('7', self::userRow(false, 'Ann'));

        $this->assertTrue($declared->matchesRenderedRow('7', self::userRow(true, 'Ann')));
    }

    /**
     * Builds a wire row of the users table: a record per slot, and a flag no cell draws.
     *
     * @param bool $admin Flag the row carries and no cell draws
     * @param string $name Name the row is drawn with
     * @param string $presence Presence the connections slot carries
     * @return array{rowKey: int|string, slots: array<string, mixed>} Wire row of user 7
     */
    private static function userRow(bool $admin, string $name, string $presence = 'online'): array
    {
        return [
            PagePayload::rowKey => '7',
            PagePayload::slots => [
                'users' => ['id' => 7, 'name' => $name, 'admin' => $admin],
                'connections' => ['presence' => $presence, 'onlineSessionCount' => 1],
            ],
        ];
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
