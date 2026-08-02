<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Notification;

use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Collection\Notifications as ObjectNotifications;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the notification table activation gate (HIL-505).
 *
 * The framework ships hilos_notification and hilos_notification_delivery as
 * migration stubs, so a project that registers the collections but never copied
 * the stub reaches a table that does not exist. Every entry point that owns such
 * a table asks the gate first, so the failure names the table and the stub to
 * copy instead of surfacing a driver-level "table doesn't exist". The preference
 * table keeps its silent degrade instead — a missing mute row has a correct
 * default, a missing notification row does not — and that contract is locked in
 * {@see NotificationPreferenceTest::testMutedChannelsDegradesToAllowedWhenTableNotActivated()}.
 */
final class NotificationTableActivationTest extends TestCase
{
    public function testCreateForRejectsAnUnactivatedNotificationTable(): void
    {
        $notifications = $this->notifications();

        $this->expectException(TableNotActivatedException::class);
        $notifications->createFor(1, 'demo.type', 'info', 'Title', null, null);
    }

    public function testListForUserRejectsAnUnactivatedNotificationTable(): void
    {
        $notifications = $this->notifications();

        $this->expectException(TableNotActivatedException::class);
        $notifications->listForUser(1);
    }

    public function testCountUnreadForUserRejectsAnUnactivatedNotificationTable(): void
    {
        $notifications = $this->notifications();

        $this->expectException(TableNotActivatedException::class);
        $notifications->countUnreadForUser(1);
    }

    public function testMarkAllReadForUserRejectsAnUnactivatedNotificationTable(): void
    {
        $notifications = $this->notifications();

        $this->expectException(TableNotActivatedException::class);
        $notifications->markAllReadForUser(1);
    }

    public function testCreatePendingRejectsAnUnactivatedDeliveryTable(): void
    {
        $deliveries = $this->deliveries();

        $this->expectException(TableNotActivatedException::class);
        $deliveries->createPending(1, 'email');
    }

    public function testFindForRejectsAnUnactivatedDeliveryTable(): void
    {
        $deliveries = $this->deliveries();

        $this->expectException(TableNotActivatedException::class);
        $deliveries->findFor(1, 'email');
    }

    public function testTheFailureNamesTheTableAndTheStubToCopy(): void
    {
        $notifications = $this->notifications();

        try {
            $notifications->listForUser(1);
            self::fail('Expected TableNotActivatedException');
        } catch (TableNotActivatedException $e) {
            self::assertStringContainsString(EntityNotification::_table, $e->getMessage());
            self::assertStringContainsString(
                'create_' . EntityNotification::_table . '.sql',
                $e->getMessage(),
            );
        }
    }

    public function testAFailedCheckIsNotRemembered(): void
    {
        // The check is memoized per collection so it does not repeat on every read
        // and write, but only success is remembered: a project that activates the
        // table later in the same process must not stay poisoned by an early miss.
        $notifications = $this->notifications();

        try {
            $notifications->listForUser(1);
            self::fail('Expected TableNotActivatedException');
        } catch (TableNotActivatedException) {
            // Expected on the first call.
        }

        $this->expectException(TableNotActivatedException::class);
        $notifications->listForUser(1);
    }

    public function testTheDeliveryFailureNamesItsOwnTable(): void
    {
        $deliveries = $this->deliveries();

        try {
            $deliveries->createPending(1, 'email');
            self::fail('Expected TableNotActivatedException');
        } catch (TableNotActivatedException $e) {
            self::assertStringContainsString(EntityNotificationDelivery::_table, $e->getMessage());
        }
    }

    /**
     * Builds the notifications collection against an empty schema.
     *
     * @return ObjectNotifications Collection whose table is not activated
     */
    private function notifications(): ObjectNotifications
    {
        Schema::reset();

        return ObjectNotifications::initDB(Objects::LAZY_STRATEGY_KEY);
    }

    /**
     * Builds the deliveries collection against an empty schema.
     *
     * @return ObjectNotificationDeliveries Collection whose table is not activated
     */
    private function deliveries(): ObjectNotificationDeliveries
    {
        Schema::reset();

        return ObjectNotificationDeliveries::initDB(Objects::LAZY_STRATEGY_KEY);
    }
}
