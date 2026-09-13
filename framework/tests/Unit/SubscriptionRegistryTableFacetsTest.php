<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\TableViewportSubscription;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for where a connection's options to count are kept, and for how long (HIL-240).
 *
 * The list is declared once, when the view mounts, and has to outlive every window after it - so
 * it lives beside the viewport, which is rebuilt from each window frame, and leaves exactly when
 * the viewport leaves with its page.
 */
final class SubscriptionRegistryTableFacetsTest extends TestCase
{
    /** Options of one filter, the list most cases keep. */
    private const array CHANNELS = ['channel' => ['email', 'sms']];

    public function testTheOptionsAreKeptPerConnectionAndPerTable(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFacets('ak', 'deliveries', self::CHANNELS);
        $registry->setTableFacets('ak', 'workers', ['node' => ['node-1']]);

        $this->assertSame(self::CHANNELS, $registry->getTableFacets('ak', 'deliveries'));
        $this->assertSame(['node' => ['node-1']], $registry->getTableFacets('ak', 'workers'));
        $this->assertSame([], $registry->getTableFacets('other-ak', 'deliveries'));
    }

    /**
     * Turning a page replaces the viewport; the options the view declared at mount must survive it.
     */
    public function testANewWindowForTheSameTableKeepsTheOptions(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFacets('ak', 'deliveries', self::CHANNELS);

        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'deliveries', pageIndex: 1));
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'deliveries', pageIndex: 2));

        $this->assertSame(self::CHANNELS, $registry->getTableFacets('ak', 'deliveries'));
    }

    public function testResubscribingThePageKeepsTheOptions(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page', []);
        $registry->setTableFacets('ak', 'deliveries', self::CHANNELS);

        $registry->subscribeToPage('ak', 'page', []);

        $this->assertSame(self::CHANNELS, $registry->getTableFacets('ak', 'deliveries'));
    }

    public function testLeavingThePageTakesTheOptionsWithIt(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'page', []);
        $registry->setTableFacets('ak', 'deliveries', self::CHANNELS);

        $registry->unsubscribeFromPage('ak', 'page');

        $this->assertSame([], $registry->getTableFacets('ak', 'deliveries'));
    }

    public function testClosingTheConnectionTakesTheOptionsWithIt(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFacets('ak', 'deliveries', self::CHANNELS);

        $registry->unsubscribeFromAll('ak');

        $this->assertSame([], $registry->getTableFacets('ak', 'deliveries'));
    }

    public function testForgettingATablesWindowForgetsItsOptionsAndOnlyItsOwn(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'deliveries'));
        $registry->setTableFacets('ak', 'deliveries', self::CHANNELS);
        $registry->setTableFacets('ak', 'workers', ['node' => ['node-1']]);

        $registry->forgetTableViewport('ak', 'deliveries');

        $this->assertSame([], $registry->getTableFacets('ak', 'deliveries'));
        $this->assertSame(['node' => ['node-1']], $registry->getTableFacets('ak', 'workers'));
    }

    public function testEmptyAcceptKeyIsIgnored(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->setTableFacets('', 'deliveries', self::CHANNELS);

        $this->assertSame([], $registry->getTableFacets('', 'deliveries'));
    }
}
