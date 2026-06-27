<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SignalRouter table viewport delegation to the subscription registry.
 */
final class SignalRouterTableViewportTest extends TestCase
{
    public function testSetAndGetTableViewportThroughTheRouter(): void
    {
        $router = new SignalRouter();
        $router->setTableViewport('ak', new TableViewportSubscription(
            tableKey: 'settings',
            offset: 10,
            limit: 10,
        ));

        $viewport = $router->getTableViewport('ak', 'settings');

        $this->assertNotNull($viewport);
        $this->assertSame('settings', $viewport->tableKey);
        $this->assertSame(10, $viewport->offset);
    }

    public function testGetTableViewportsReturnsAllForTheConnection(): void
    {
        $router = new SignalRouter();
        $router->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings'));
        $router->setTableViewport('ak', new TableViewportSubscription(tableKey: 'users'));

        $this->assertSame(['settings', 'users'], array_keys($router->getTableViewports('ak')));
    }

    public function testGetTableViewportReturnsNullWhenAbsent(): void
    {
        $this->assertNull((new SignalRouter())->getTableViewport('ak', 'settings'));
    }
}
