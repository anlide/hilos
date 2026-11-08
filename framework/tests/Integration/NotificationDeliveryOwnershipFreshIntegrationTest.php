<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\DataProvider;

/** Only these cases deliberately diverge loaded ORM memory from SQL; the canonical harness resets it. */
#[IntegrationTest]
final class NotificationDeliveryOwnershipFreshIntegrationTest extends NotificationDeliveryOwnershipIntegrationTestCase implements BreaksOrmState
{
    /** @return iterable<string, array{bool}> Fresh immutability applies to partition and transition writers */
    public static function writers(): iterable
    {
        yield 'channel' => [true];
        yield 'transition library' => [false];
    }

    /** @param bool $channelWriter Whether to execute under the partitioned claimant */
    #[DataProvider('writers')]
    public function testFreshPersistedIdentityOutranksTheObjectsCleanBaseline(bool $channelWriter): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $object = Hilos::$db->notificationDeliveries->getObjectCollection()[$id];
        Database::sql('UPDATE hilos_notification_delivery SET routing_key = ? WHERE id = ?', [1, $id]);
        self::assertFalse($object->hasChanges());
        $write = function () use ($object): void {
            $object->status = DeliveryStatus::SENT;
            $this->assertRefusedWithoutChanges($object->sync(...));
            self::assertSame(DeliveryStatus::PENDING, $object->status);
            self::assertSame(0, $object->routingKey);
        };
        if ($channelWriter) {
            $this->asClaimant(self::mailOwner(), $write);
        } else {
            $write();
        }
        self::assertSame([['routing_key' => '1', 'status' => DeliveryStatus::PENDING]], Database::sql(
            'SELECT routing_key, status FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }

    public function testMissingDeliveryAndRecreationAtTheSamePairCannotRetargetOldObjects(): void
    {
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $oldObject = Hilos::$db->notificationDeliveries->getObjectCollection()[$id];
        $oldItem = Hilos::$db->notificationDeliveries[$id];
        Database::sql('DELETE FROM hilos_notification_delivery WHERE id = ?', [$id]);
        $replacement = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        self::assertNotSame($id, $replacement);

        $this->asClaimant(self::mailOwner(), function () use ($oldObject, $oldItem, $replacement): void {
            $this->assertRefusedWithoutChanges(static function () use ($oldObject): void {
                $oldObject->status = DeliveryStatus::SENT;
                $oldObject->sync();
            });
            $this->assertRefusedWithoutChanges($oldObject->delete(...));
            $this->assertRefusedWithoutChanges($oldItem->actions->markSent(...));
            $this->assertRefusedWithoutChanges($oldItem->actions->delete(...));
            Hilos::$db->notificationDeliveries[$replacement]->actions->markSent();
        });
        self::assertSame([['id' => (string)$replacement, 'status' => DeliveryStatus::SENT]], Database::sql(
            'SELECT id, status FROM hilos_notification_delivery ORDER BY id',
        )->rows());
    }

    public function testParentDisappearanceDoesNotMoveTheSavedOwnerOrForceASetRead(): void
    {
        Database::sql("INSERT INTO hilos_user (id, name) VALUES (1893, 'Private recipient')");
        Database::sql(
            'INSERT INTO hilos_notification (id, user_id, type, title) VALUES (?, ?, ?, ?)',
            [11, 1893, 'test.notification', 'Private notification text'],
        );
        $id = Hilos::$db->notificationDeliveries->actions->createPending(11, MailDeliveryChannel::NAME, 0)->id;
        $this->asClaimant(self::mailOwner(), static fn () => Hilos::$db->notificationDeliveries[$id]->actions->beginAttempt());
        Database::sql('DELETE FROM hilos_notification WHERE id = ?', [11]);
        $this->asClaimant(self::mailOwner(), static fn () => Hilos::$db->notificationDeliveries[$id]->actions->markSent());
        self::assertSame([['notification_id' => '11', 'routing_key' => '0', 'status' => DeliveryStatus::SENT]], Database::sql(
            'SELECT notification_id, routing_key, status FROM hilos_notification_delivery WHERE id = ?', [$id],
        )->rows());
    }
}
