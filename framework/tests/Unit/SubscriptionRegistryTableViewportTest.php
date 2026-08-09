<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\DTO\TableSortDTO;
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
            sort: new TableSortDTO('key', TableConstants::ORDER_DESC),
            offset: 10,
            limit: 10,
        ));

        $viewport = $registry->getTableViewport('ak', 'settings');

        $this->assertNotNull($viewport);
        $this->assertSame('settings', $viewport->tableKey);
        $this->assertSame(['search' => 'theme'], $viewport->filter);
        $this->assertEquals(new TableSortDTO('key', TableConstants::ORDER_DESC), $viewport->sort);
        $this->assertSame(10, $viewport->offset);
        $this->assertSame(10, $viewport->limit);
    }

    public function testSetTableViewportReplacesTheSameTable(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings', offset: 0));
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings', offset: 20));

        $this->assertSame(20, $registry->getTableViewport('ak', 'settings')?->offset);
    }

    public function testRecordWindowTracksDeliveredRowIdsAndTotal(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'settings');
        $viewport->recordWindow(['a', 'b', 'c'], 42);

        $this->assertSame(['a', 'b', 'c'], $viewport->rowIds());
        $this->assertSame(42, $viewport->totalCount());
        $this->assertTrue($viewport->hasRow('b'));
        $this->assertFalse($viewport->hasRow('z'));
    }

    public function testForgetRowDropsItFromTheDeliveredSet(): void
    {
        $viewport = new TableViewportSubscription(tableKey: 'settings');
        $viewport->recordWindow(['a', 'b', 'c'], 3);
        $viewport->forgetRow('b');

        $this->assertSame(['a', 'c'], $viewport->rowIds());
        $this->assertFalse($viewport->hasRow('b'));
    }

    public function testNavigatingToAnotherPageClearsTableViewports(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page1', []);
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));

        $registry->subscribeToPage('ak', 'page2', []);

        $this->assertNull($registry->getTableViewport('ak', 'settings'));
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
}
