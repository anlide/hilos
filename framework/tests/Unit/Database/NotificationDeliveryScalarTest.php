<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Database\View\Item\NotificationDelivery as ViewNotificationDelivery;
use Hilos\Notification\Delivery\DeliveryStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationDeliveryScalarTest extends TestCase
{
    /** @return iterable<string, array{?int}> Original routing dimensions, before any shard modulo */
    public static function routingKeys(): iterable
    {
        yield 'legacy or anonymized' => [null];
        yield 'zero' => [0];
        yield 'unsigned crc32' => [4294967295];
        yield 'PHP integer ceiling' => [PHP_INT_MAX];
    }

    /** @param ?int $routingKey Stored dimension, including the legacy null */
    #[DataProvider('routingKeys')]
    public function testHydrationAndScalarSerializationPreserveTheOriginalDimension(?int $routingKey): void
    {
        $row = [
            'id' => 17,
            'notification_id' => 29,
            'channel' => 'test-channel',
            'routing_key' => $routingKey === null ? null : (string) $routingKey,
            'status' => DeliveryStatus::PENDING,
            'attempts' => 2,
            'last_error' => 'previous attempt',
            'created_at' => 1793528430123,
            'updated_at' => 1793618491123,
            'delivered_at' => null,
        ];
        $entity = EntityNotificationDelivery::fromRow($row);
        $object = ObjectNotificationDelivery::fromEntity($entity);
        $view = new ViewNotificationDelivery($object);

        self::assertSame($routingKey, $entity->routing_key);
        self::assertSame($routingKey, $object->routingKey);
        self::assertSame($routingKey, $view->routingKey);
        self::assertSame(array_replace($row, ['routing_key' => $routingKey]), $entity->toArray());
        $expected = [
            'id' => 17,
            'notificationId' => 29,
            'channel' => 'test-channel',
            'routingKey' => $routingKey,
            'status' => DeliveryStatus::PENDING,
            'attempts' => 2,
            'lastError' => 'previous attempt',
            'createdAt' => 1793528430123,
            'updatedAt' => 1793618491123,
            'deliveredAt' => null,
        ];
        self::assertSame($expected, $object->toArray());
        self::assertSame($expected, $view->toArray());

        foreach ([0, -1, 4102444800123] as $moment) {
            $momentEntity = EntityNotificationDelivery::fromRow(array_replace($row, [
                'created_at' => (string)$moment, 'updated_at' => (string)$moment, 'delivered_at' => (string)$moment,
            ]));
            $momentObject = ObjectNotificationDelivery::fromEntity($momentEntity);
            $momentView = new ViewNotificationDelivery($momentObject);
            foreach (['created_at', 'updated_at', 'delivered_at'] as $column) {
                self::assertSame($moment, $momentEntity->toArray()[$column]);
            }
            foreach (['createdAt', 'updatedAt', 'deliveredAt'] as $field) {
                self::assertSame($moment, $momentObject->toArray()[$field]);
                self::assertSame($moment, $momentView->toArray()[$field]);
            }
            $momentObject->deliveredAt = null;
            self::assertNull($momentView->deliveredAt);
        }

        $object->routingKey = 0;
        self::assertSame(0, $entity->routing_key);
        self::assertSame(0, $view->routingKey);
        $object->routingKey = null;
        self::assertNull($entity->routing_key);
        self::assertNull($object->toArray()['routingKey']);
    }
}
