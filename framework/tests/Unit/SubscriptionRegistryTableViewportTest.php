<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for per-connection table viewport state in SubscriptionRegistry.
 */
final class SubscriptionRegistryTableViewportTest extends TestCase
{
    public function testSetAndGetTableViewport(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(
            tableKey: 'settings',
            filter: ['search' => 'theme'],
            sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_DESC)),
            limit: 10,
            anchor: new TableAnchorDTO(['key' => 'theme.dark']),
        ));

        $viewport = $registry->getTableViewport('ak', 'settings');

        $this->assertNotNull($viewport);
        $this->assertSame('settings', $viewport->tableKey);
        $this->assertSame(['search' => 'theme'], $viewport->filter);
        $this->assertEquals(TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_DESC)), $viewport->sort);
        $this->assertSame(10, $viewport->limit);
        $this->assertSame(['key' => 'theme.dark'], $viewport->anchor?->toArray());
    }

    public function testSetTableViewportReplacesTheSameTable(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings', limit: 10));
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings', limit: 20));

        $this->assertSame(20, $registry->getTableViewport('ak', 'settings')?->limit);
    }

    public function testRecordWindowTracksDeliveredRowIdsAndTotal(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'settings');
        $viewport->recordWindow(self::windowOf(['a', 'b', 'c']), 42, true, null, null);

        $this->assertSame(['a', 'b', 'c'], $viewport->rowIds());
        $this->assertSame(42, $viewport->totalCount());
        $this->assertTrue($viewport->hasRow('b'));
        $this->assertFalse($viewport->hasRow('z'));
    }

    public function testRecordWindowSaysRowsFollowWhenThePlaceAndTheTotalSaySo(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, true, null, null, [], null, 0);

        $this->assertTrue($viewport->hasRowsAfter());
    }

    public function testRecordWindowSaysNoRowsFollowWhenTheWindowHoldsTheEnd(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 2, true, null, null, [], null, 0);

        $this->assertFalse($viewport->hasRowsAfter());
    }

    public function testRecordWindowForgetsTheWordWhenTheTotalIsNotExact(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, false, null, null, [], null, 0);

        $this->assertNull($viewport->hasRowsAfter());
    }

    public function testRecordWindowForgetsTheWordWhenThePlaceBeforeItIsUnknown(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, true, null, null);

        $this->assertNull($viewport->hasRowsAfter());
    }

    public function testRecordWindowForgetsTheWordWhenTheWindowIsEmpty(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf([]), 5, true, null, null, [], null, 0);

        $this->assertNull($viewport->hasRowsAfter());
    }

    public function testRecordWindowForgetsTheWordWhenTheWindowHasNoOrder(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'settings', limit: 2);
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, true, null, null, [], null, 0);

        $this->assertNull($viewport->hasRowsAfter());
    }

    public function testRecordWindowForgetsTheWordWhenTheWindowHasNoLimit(): void
    {
        $viewport = new TableViewportSubscription(
            tableKey: 'settings',
            sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_ASC)),
        );
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, true, null, null, [], null, 0);

        $this->assertNull($viewport->hasRowsAfter());
    }

    public function testRecordTotalForgetsTheWordWhenTheTotalStopsBeingExact(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, true, null, null, [], null, 0);
        $viewport->recordTotal(TableConstants::COUNT_CEILING, false);

        $this->assertNull($viewport->hasRowsAfter());
    }

    public function testRecordTotalTakesAWordWhenOneIsGiven(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, true, null, null, [], null, 0);
        $viewport->recordTotal(5, true, false);

        $this->assertFalse($viewport->hasRowsAfter());
    }

    public function testRecordTotalLeavesTheWordWhenNoneIsGiven(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 5, true, null, null, [], null, 0);
        $viewport->recordTotal(6, true);

        $this->assertSame(6, $viewport->totalCount());
        $this->assertTrue($viewport->hasRowsAfter());
    }

    public function testWithRenderedCarriesTheWord(): void
    {
        $viewport = self::orderedViewport();
        $viewport->recordWindow(self::windowOf(['a', 'b']), 2, true, null, null, [], null, 0);

        $carried = $viewport->withRendered([], []);

        $this->assertFalse($carried->hasRowsAfter());
        $this->assertFalse($viewport->hasRowsAfter());
    }

    public function testNumericRowKeysComeBackAsStrings(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'settings');
        $viewport->recordWindow(self::windowOf(['7', '11']), 2, true, null, null);

        // PHP hands a numeric key back out of an array as an int, so the map the
        // window is kept in would silently retype rows every table keyed by an id.
        $this->assertSame(['7', '11'], $viewport->rowIds());
        $this->assertTrue($viewport->hasRow('7'));
    }

    public function testForgetRowDropsItFromTheDeliveredSet(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'settings');
        $viewport->recordWindow(self::windowOf(['a', 'b', 'c']), 3, true, null, null);
        $viewport->forgetRow('b');

        $this->assertSame(['a', 'c'], $viewport->rowIds());
        $this->assertFalse($viewport->hasRow('b'));
    }

    public function testSubscribingKeepsTheWindowsTheAnswerToThatSubscriptionOpened(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page1', []);
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));

        $registry->subscribeToPage('ak', 'page2', []);

        // The subscription is recorded AFTER it has been answered, and the answer is where a
        // page's windows are opened now (HIL-642): dropping them here would throw away the
        // very windows the frame just delivered. Leaving a page is what drops its windows,
        // and every way one page replaces another goes through the unsubscribe below.
        $this->assertNotNull($registry->getTableViewport('ak', 'settings'));
    }

    public function testUnsubscribingFromThePageClearsTableViewports(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page', []);
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));

        $registry->unsubscribeFromPage('ak', 'page');

        $this->assertSame([], $registry->getTableViewports('ak'));
    }

    public function testUnsubscribingFromAllClearsTableViewports(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));

        $registry->unsubscribeFromAll('ak');

        $this->assertSame([], $registry->getTableViewports('ak'));
    }

    public function testForgetTableViewportRemovesOnlyThatTable(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'users'));

        $registry->forgetTableViewport('ak', 'settings');

        $this->assertNull($registry->getTableViewport('ak', 'settings'));
        $this->assertNotNull($registry->getTableViewport('ak', 'users'));
    }

    public function testEmptyAcceptKeyIsIgnored(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('', new TableViewportSubscription(tableKey: 'settings'));

        $this->assertSame([], $registry->getTableViewports(''));
    }

    /**
     * The mark is a test-and-set: the first failure of a window owes the connection a frame and
     * every later one does not, until something replaces the frozen rows (HIL-1139).
     */
    public function testMarkingAFrozenWindowAnswersOnlyTheFirstTime(): void
    {
        $registry = new SubscriptionRegistry();

        $this->assertFalse($registry->isTableWindowOwed('ak', 'settings'));
        $this->assertTrue($registry->oweTableWindow('ak', 'settings'));
        $this->assertFalse($registry->oweTableWindow('ak', 'settings'));
        $this->assertTrue($registry->isTableWindowOwed('ak', 'settings'));
        $this->assertFalse($registry->isTableWindowOwed('ak', 'users'));
    }

    public function testReadingTheDebtLeavesItStanding(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->oweTableWindow('ak', 'settings');

        $registry->isTableWindowOwed('ak', 'settings');

        $this->assertTrue($registry->isTableWindowOwed('ak', 'settings'));
    }

    public function testClearingTheDebtAnswersWhetherItStood(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->oweTableWindow('ak', 'settings');
        $registry->oweTableWindow('ak', 'users');

        $this->assertTrue($registry->clearTableWindowDebt('ak', 'settings'));
        $this->assertFalse($registry->clearTableWindowDebt('ak', 'settings'));
        $this->assertFalse($registry->isTableWindowOwed('ak', 'settings'));
        $this->assertTrue($registry->isTableWindowOwed('ak', 'users'));
        $this->assertTrue($registry->oweTableWindow('ak', 'settings'));
    }

    public function testAnEmptyAcceptKeyIsNeverMarkedFrozen(): void
    {
        $registry = new SubscriptionRegistry();

        $this->assertFalse($registry->oweTableWindow('', 'settings'));
        $this->assertFalse($registry->isTableWindowOwed('', 'settings'));
    }

    public function testSubscribingKeepsTheWindowDebtsTheAnswerSettled(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->oweTableWindow('ak', 'settings');
        $registry->oweTableWindow('other', 'settings');

        $registry->subscribeToPage('ak', 'page', []);

        $this->assertTrue($registry->isTableWindowOwed('ak', 'settings'));
        $this->assertTrue($registry->isTableWindowOwed('other', 'settings'));
    }

    public function testUnsubscribingFromThePageDropsTheDebts(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page', []);
        $registry->oweTableWindow('ak', 'settings');

        $registry->unsubscribeFromPage('ak', 'page');

        $this->assertFalse($registry->isTableWindowOwed('ak', 'settings'));
    }

    public function testUnsubscribingFromAllDropsTheDebts(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->oweTableWindow('ak', 'settings');

        $registry->unsubscribeFromAll('ak');

        $this->assertFalse($registry->isTableWindowOwed('ak', 'settings'));
    }

    public function testForgettingAViewportDropsOnlyThatTablesDebt(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'users'));
        $registry->oweTableWindow('ak', 'settings');
        $registry->oweTableWindow('ak', 'users');

        $registry->forgetTableViewport('ak', 'settings');

        $this->assertFalse($registry->isTableWindowOwed('ak', 'settings'));
        $this->assertTrue($registry->isTableWindowOwed('ak', 'users'));
    }

    /**
     * Builds an ordered window of a fixed size, the only shape that can hold a word on its edge.
     *
     * @param int $limit Window size
     * @return TableViewportSubscription Ordered window before any build is recorded
     */
    private static function orderedViewport(int $limit = 2): TableViewportSubscription
    {
        return new TableViewportSubscription(
            tableKey: 'settings',
            sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_ASC)),
            limit: $limit,
        );
    }

    /**
     * Turns a list of row-id keys into a window of placeholder wire rows.
     *
     * These tests ask the subscription what it remembers, not what a row body
     * says, so the body is a placeholder and only the keys carry meaning.
     *
     * @param list<string> $rowIds Row-id keys the connection holds, in display order
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
