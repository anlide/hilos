<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/** Saved delivery coordinates, independent of the mutable ORM row. */
final readonly class NotificationDeliveryIdentity
{
    /**
     * @param int $deliveryId Delivery primary key
     * @param int $notificationId Original notification primary key, including an orphan's
     * @param string $channel Saved channel name
     * @param ?int $routingKey Original routing dimension, null for legacy rows
     */
    public function __construct(
        public int $deliveryId,
        public int $notificationId,
        public string $channel,
        public ?int $routingKey,
    ) {
    }
}
