<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\View\Collection\NotificationDeliveries as DbCollectionNotificationDeliveries;
use Hilos\Database\View\Item\NotificationDelivery;
use Hilos\HilosException;

/**
 * @extends DbActions<NotificationDelivery, ObjectNotificationDeliveries>
 * @property-read DbCollectionNotificationDeliveries $collection
 * @property-read ObjectNotificationDeliveries $objectCollection
 */
class NotificationDeliveriesActions extends DbActions
{
    /**
     * @param int $notificationId Notification primary key, a soft reference
     * @param string $channel Target channel
     * @param ?int $routingKey Original nonnegative routing dimension, null for legacy callers
     * @return NotificationDelivery Created pending delivery with its assigned primary key
     * @throws HilosException When ownership, validation, insertion or wrapping refuses the row
     */
    public function createPending(int $notificationId, string $channel, ?int $routingKey = null): NotificationDelivery
    {
        $this->ensureCanCreateInSet((string)$notificationId);

        return $this->createDbItemFromObject($this->objectCollection->createPending($notificationId, $channel, $routingKey));
    }
}
