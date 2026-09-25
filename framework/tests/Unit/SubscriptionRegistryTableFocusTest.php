<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\TableViewportSubscription;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for where the row a connection holds in focus is kept, and for how long (HIL-1050).
 *
 * The row is named when a dialog opens over it and has to be followed through every window the tab
 * asks for while the dialog stays open - so it lives beside the viewport, as the facets do, and
 * leaves when the viewport leaves with its page, or when the tab lets it go.
 */
final class SubscriptionRegistryTableFocusTest extends TestCase
{
    public function testNoRowIsHeldUntilOneIsNamed(): void
    {
        $registry = new SubscriptionRegistry();

        $this->assertNull($registry->getTableFocus('ak', 'settings'));
    }

    public function testTheRowIsKeptPerConnectionAndPerTable(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFocus('ak', 'settings', 'site.title');
        $registry->setTableFocus('ak', 'workers', 'node-1');

        $this->assertSame('site.title', $registry->getTableFocus('ak', 'settings'));
        $this->assertSame('node-1', $registry->getTableFocus('ak', 'workers'));
        $this->assertNull($registry->getTableFocus('other-ak', 'settings'));
    }

    public function testASecondRowOnTheSameTableReplacesTheFirst(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFocus('ak', 'settings', 'site.title');
        $registry->setTableFocus('ak', 'settings', 'site.locale');

        $this->assertSame('site.locale', $registry->getTableFocus('ak', 'settings'));
    }

    /**
     * Turning a page replaces the viewport; the row the dialog holds must survive it.
     */
    public function testANewWindowForTheSameTableKeepsTheRow(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFocus('ak', 'settings', 'site.title');

        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings', pageIndex: 1));
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings', pageIndex: 2));

        $this->assertSame('site.title', $registry->getTableFocus('ak', 'settings'));
    }

    public function testResubscribingThePageKeepsTheRow(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page', []);
        $registry->setTableFocus('ak', 'settings', 'site.title');

        $registry->subscribeToPage('ak', 'page', []);

        $this->assertSame('site.title', $registry->getTableFocus('ak', 'settings'));
    }

    public function testLeavingThePageTakesTheRowWithIt(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page', []);
        $registry->setTableFocus('ak', 'settings', 'site.title');

        $registry->unsubscribeFromPage('ak', 'page');

        $this->assertNull($registry->getTableFocus('ak', 'settings'));
    }

    public function testClosingTheConnectionTakesTheRowWithIt(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFocus('ak', 'settings', 'site.title');

        $registry->unsubscribeFromAll('ak');

        $this->assertNull($registry->getTableFocus('ak', 'settings'));
    }

    public function testForgettingATablesWindowForgetsItsRowAndOnlyItsOwn(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));
        $registry->setTableFocus('ak', 'settings', 'site.title');
        $registry->setTableFocus('ak', 'workers', 'node-1');

        $registry->forgetTableViewport('ak', 'settings');

        $this->assertNull($registry->getTableFocus('ak', 'settings'));
        $this->assertSame('node-1', $registry->getTableFocus('ak', 'workers'));
    }

    public function testClearingReleasesTheRowAndOnlyThatTables(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFocus('ak', 'settings', 'site.title');
        $registry->setTableFocus('ak', 'workers', 'node-1');

        $registry->clearTableFocus('ak', 'settings');

        $this->assertNull($registry->getTableFocus('ak', 'settings'));
        $this->assertSame('node-1', $registry->getTableFocus('ak', 'workers'));
    }

    public function testEmptyAcceptKeyIsIgnored(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFocus('', 'settings', 'site.title');

        $this->assertNull($registry->getTableFocus('', 'settings'));
    }
}
