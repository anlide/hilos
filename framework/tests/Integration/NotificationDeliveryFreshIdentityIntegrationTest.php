<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Hilos;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;

/** These cases deliberately diverge persisted rows from loaded memory; the harness resets after rollback. */
#[IntegrationTest]
final class NotificationDeliveryFreshIdentityIntegrationTest extends NotificationDeliveryApiIntegrationTestCase implements BreaksOrmState
{
    /** Saved coordinates do not inherit dirty identity/status fields or mutate an earlier snapshot. */
    public function testFreshIdentityAndPendingScanIgnoreDirtyObjects(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, 'saved-channel', 71)->id;
        $snapshot = Hilos::$db->notificationDeliveries->readIdentity($id);
        self::assertInstanceOf(NotificationDeliveryIdentity::class, $snapshot);
        $cached = Hilos::$db->notificationDeliveries->getObjectCollection()[$id];
        $cached->notificationId = 99;
        $cached->channel = 'dirty-channel';
        $cached->routingKey = 900;
        $cached->status = DeliveryStatus::SENT;

        $fresh = Hilos::$db->notificationDeliveries->readIdentity($id);
        self::assertNotSame($snapshot, $fresh);
        self::assertEquals(new NotificationDeliveryIdentity($id, 11, 'saved-channel', 71), $fresh);
        $page = Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('saved-channel', 0, 1);
        self::assertCount(1, $page);
        self::assertEquals($fresh, $page->first());
        self::assertCount(0, Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('dirty-channel', 0, 1));
        self::assertSame($cached, Hilos::$db->notificationDeliveries->getObjectCollection()[$id]);
        self::assertSame(900, $cached->routingKey);

        Database::sql(
            'UPDATE hilos_notification_delivery SET notification_id = ?, channel = ?, routing_key = ? WHERE id = ?',
            [22, 'changed-channel', 82, $id],
        );
        self::assertEquals(new NotificationDeliveryIdentity($id, 22, 'changed-channel', 82),
            Hilos::$db->notificationDeliveries->getObjectCollection()->readIdentity($id));
        self::assertEquals(new NotificationDeliveryIdentity($id, 11, 'saved-channel', 71), $snapshot);
        self::assertEquals($snapshot, $page->first());
        self::assertCount(0, Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('saved-channel', 0, 1));
        self::assertSame(82, Hilos::$db->notificationDeliveries
            ->pendingIdentitiesAfter('changed-channel', 0, 1)->first()->routingKey);
    }

    /** The fresh projection observes disappearance even while the View still has the old wrapper. */
    public function testFreshIdentityDoesNotReturnAWarmedRowAfterExternalDeletion(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, 'test-channel', null)->id;
        $held = Hilos::$db->notificationDeliveries[$id];
        Database::sql('DELETE FROM hilos_notification_delivery WHERE id = ?', [$id]);
        self::assertSame($held, Hilos::$db->notificationDeliveries[$id]);
        self::assertNull(Hilos::$db->notificationDeliveries->readIdentity($id));
        self::assertNull(Hilos::$db->notificationDeliveries->getObjectCollection()->readIdentity($id));
        self::assertCount(0, Hilos::$db->notificationDeliveries->pendingIdentitiesAfter('test-channel', 0, 1));
    }

    /** A prior miss is not a negative cache, and readers honor the Entity binding rather than ambient SQL. */
    public function testFreshReadsObserveExternalRowsAndUseTheMountedEntityConnection(): void
    {
        self::assertNull(Hilos::$db->notificationDeliveries->readIdentity(1893));
        Database::sql(
            'INSERT INTO hilos_notification_delivery (id, notification_id, channel, routing_key, status) VALUES (?, ?, ?, ?, ?)',
            [1893, 11, 'test-channel', 61, DeliveryStatus::PENDING],
        );
        $config = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(
            index: 19, host: $config->host, user: $config->user, password: $config->password,
            database: 'information_schema', port: $config->port, charset: $config->charset,
        );
        Database::useConnection(19);
        try {
            self::assertEquals(new NotificationDeliveryIdentity(1893, 11, 'test-channel', 61),
                Hilos::$db->notificationDeliveries->readIdentity(1893));
            self::assertSame(1893, Hilos::$db->notificationDeliveries
                ->pendingIdentitiesAfter('test-channel', 0, 1)->first()->deliveryId);
            self::assertArrayNotHasKey(1893, Hilos::$db->notificationDeliveries->getObjectCollection());
            self::assertSame(19, Database::getCurrentIndex());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }
}
