<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Router\SubscriptionRegistry;
use Hilos\Core\Router\TableViewportSubscription;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for dropping every group of one connection and nothing else (HIL-1284).
 *
 * The person behind a connection changed: its groups go, whoever wrote them, and everything the
 * connection holds for its page stays - the page is re-judged for the new person on its own road.
 */
final class SubscriptionRegistryGroupLeaveAllTest extends TestCase
{
    public function testEveryGroupOfTheConnectionGoes(): void
    {
        $registry = new SubscriptionRegistry();
        $this->join($registry, 'ak', 'hilos_notifications:7');
        $this->join($registry, 'ak', 'hilos_second_factor:7');
        $this->join($registry, 'ak', 'session');

        $registry->unsubscribeFromAllGroups('ak');

        $this->assertNull($registry->groupSubscriptionName('ak', 'hilos_notifications'));
        $this->assertNull($registry->groupSubscriptionName('ak', 'hilos_second_factor'));
        $this->assertNull($registry->groupSubscriptionName('ak', 'session'));
        $this->assertSame([], $registry->acceptKeysForGroup('hilos_notifications:7'));
    }

    public function testTheGroupsOfAnotherConnectionStay(): void
    {
        $registry = new SubscriptionRegistry();
        $this->join($registry, 'ak', 'hilos_notifications:7');
        $this->join($registry, 'other-ak', 'hilos_notifications:7');

        $registry->unsubscribeFromAllGroups('ak');

        $this->assertSame(['other-ak'], $registry->acceptKeysForGroup('hilos_notifications:7'));
        $this->assertSame('hilos_notifications:7', $registry->groupSubscriptionName('other-ak', 'hilos_notifications'));
    }

    public function testThePageOfTheConnectionAndItsTableStateStay(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->subscribeToPage('ak', 'profile', []);
        $registry->setTableViewport('ak', new TableViewportSubscription(tableKey: 'settings', pageIndex: 2));
        $registry->setTableFocus('ak', 'settings', 'site.title');
        $this->join($registry, 'ak', 'hilos_notifications:7');

        $registry->unsubscribeFromAllGroups('ak');

        $this->assertNotNull($registry->pageSubscription('ak'));
        $this->assertNotNull($registry->getTableViewport('ak', 'settings'));
        $this->assertSame('site.title', $registry->getTableFocus('ak', 'settings'));
    }

    public function testAConnectionWithoutGroupsOrAnEmptyKeyIsLeftAsItWas(): void
    {
        $registry = new SubscriptionRegistry();
        $this->join($registry, 'ak', 'hilos_notifications:7');

        $registry->unsubscribeFromAllGroups('never-joined');
        $registry->unsubscribeFromAllGroups('');

        $this->assertSame(['ak'], $registry->acceptKeysForGroup('hilos_notifications:7'));
    }

    /**
     * @param SubscriptionRegistry $registry Registry under test
     * @param string $acceptKey Joining connection
     * @param string $group Full group name
     */
    private function join(SubscriptionRegistry $registry, string $acceptKey, string $group): void
    {
        $registry->subscribeToGroup($acceptKey, $group, []);
    }
}
