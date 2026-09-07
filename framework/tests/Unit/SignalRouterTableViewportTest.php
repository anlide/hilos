<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
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
            limit: 10,
            anchor: new TableAnchorDTO(['key' => 'theme.dark']),
        ));

        $viewport = $router->getTableViewport('ak', 'settings');

        $this->assertNotNull($viewport);
        $this->assertSame('settings', $viewport->tableKey);
        $this->assertSame(['key' => 'theme.dark'], $viewport->anchor?->toArray());
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
        $this->assertNull(new SignalRouter()->getTableViewport('ak', 'settings'));
    }

    public function testTheWindowsATabReportedAreHandedToTheAnswerBeingBuiltForIt(): void
    {
        $router = new SignalRouter();
        $reported = ['settings' => new TableWindowDescriptorDTO(limit: 10)];
        $router->reportTableWindows('ak', $reported);

        // Held for one connection and one answer: another connection's answer is a cold entry
        // as far as this map is concerned, and must not be built out of somebody else's window.
        $this->assertSame([], $router->takeReportedTableWindows('other-ak'));
        $this->assertSame($reported, $router->takeReportedTableWindows('ak'));
    }

    public function testTakingTheReportedWindowsLeavesNothingBehind(): void
    {
        $router = new SignalRouter();
        $router->reportTableWindows('ak', ['settings' => new TableWindowDescriptorDTO(limit: 10)]);

        $router->takeReportedTableWindows('ak');

        // A page re-sent to this same connection later is not the subscription that reported
        // them, and the window they name may by then be one the tab has moved on from.
        $this->assertSame([], $router->takeReportedTableWindows('ak'));
    }
}
