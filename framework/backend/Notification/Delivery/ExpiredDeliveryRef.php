<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

/** Saved delivery coordinates and age from one retention-page snapshot. */
final readonly class ExpiredDeliveryRef
{
    /**
     * @param NotificationDeliveryIdentity $identity Saved ownership coordinates
     * @param int $createdAt Saved creation moment in epoch milliseconds
     */
    public function __construct(
        public NotificationDeliveryIdentity $identity,
        public int $createdAt,
    ) {
    }
}
