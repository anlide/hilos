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

        $this->assertFalse($registry->isTableViewportFrozen('ak', 'settings'));
        $this->assertTrue($registry->markTableViewportFrozen('ak', 'settings'));
        $this->assertFalse($registry->markTableViewportFrozen('ak', 'settings'));
        $this->assertTrue($registry->isTableViewportFrozen('ak', 'settings'));
        $this->assertFalse($registry->isTableViewportFrozen('ak', 'users'));
    }

    public function testReadingTheFrozenMarkLeavesItStanding(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->markTableViewportFrozen('ak', 'settings');

        $registry->isTableViewportFrozen('ak', 'settings');

        $this->assertTrue($registry->isTableViewportFrozen('ak', 'settings'));
    }

    public function testClearingTheFrozenMarkAnswersWhetherItStood(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->markTableViewportFrozen('ak', 'settings');
        $registry->markTableViewportFrozen('ak', 'users');

        $this->assertTrue($registry->clearTableViewportFrozen('ak', 'settings'));
        $this->assertFalse($registry->clearTableViewportFrozen('ak', 'settings'));
        $this->assertFalse($registry->isTableViewportFrozen('ak', 'settings'));
        $this->assertTrue($registry->isTableViewportFrozen('ak', 'users'));
        $this->assertTrue($registry->markTableViewportFrozen('ak', 'settings'));
    }

    public function testAnEmptyAcceptKeyIsNeverMarkedFrozen(): void
    {
        $registry = new SubscriptionRegistry();

        $this->assertFalse($registry->markTableViewportFrozen('', 'settings'));
        $this->assertFalse($registry->isTableViewportFrozen('', 'settings'));
    }

    public function testSubscribingDropsEveryFrozenMarkOfTheConnection(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->markTableViewportFrozen('ak', 'settings');
        $registry->markTableViewportFrozen('other', 'settings');

        $registry->subscribeToPage('ak', 'page', []);

        $this->assertFalse($registry->isTableViewportFrozen('ak', 'settings'));
        $this->assertTrue($registry->isTableViewportFrozen('other', 'settings'));
    }

    public function testUnsubscribingFromThePageDropsTheFrozenMarks(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page', []);
        $registry->markTableViewportFrozen('ak', 'settings');

        $registry->unsubscribeFromPage('ak', 'page');

        $this->assertFalse($registry->isTableViewportFrozen('ak', 'settings'));
    }

    public function testUnsubscribingFromAllDropsTheFrozenMarks(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->markTableViewportFrozen('ak', 'settings');

        $registry->unsubscribeFromAll('ak');

        $this->assertFalse($registry->isTableViewportFrozen('ak', 'settings'));
    }

    public function testForgettingAViewportDropsOnlyThatTablesFrozenMark(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'users'));
        $registry->markTableViewportFrozen('ak', 'settings');
        $registry->markTableViewportFrozen('ak', 'users');

        $registry->forgetTableViewport('ak', 'settings');

        $this->assertFalse($registry->isTableViewportFrozen('ak', 'settings'));
        $this->assertTrue($registry->isTableViewportFrozen('ak', 'users'));
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
