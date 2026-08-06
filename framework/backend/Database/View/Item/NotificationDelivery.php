<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;

/**
 * NotificationDelivery Db item - read-facing wrapper around ObjectNotificationDelivery.
 *
 * Surfaces one channel delivery row for the delivery-logs UI (HIL-201) and for the
 * channel agent that drives it. The status transitions run through the item actions.
 *
 * @extends DbItem<ObjectNotificationDelivery>
 * @property-read ?int $id
 * @property-read int $notificationId
 * @property-read string $channel
 * @property-read string $status
 * @property-read int $attempts
 * @property-read ?string $lastError
 * @property-read ?string $createdAt
 * @property-read ?string $updatedAt
 * @property-read ?string $deliveredAt
 */
final class NotificationDelivery extends DbItem
{
    /**
     * Magic getter for notification-delivery properties.
     *
     * @param string $name Property name (id, notificationId, channel, status, attempts, lastError, createdAt, updatedAt, deliveredAt)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectNotificationDelivery::id => $this->_object->id,
            ObjectNotificationDelivery::notificationId => $this->_object->notificationId,
            ObjectNotificationDelivery::channel => $this->_object->channel,
            ObjectNotificationDelivery::status => $this->_object->status,
            ObjectNotificationDelivery::attempts => $this->_object->attempts,
            ObjectNotificationDelivery::lastError => $this->_object->lastError,
            ObjectNotificationDelivery::createdAt => $this->_object->createdAt,
            ObjectNotificationDelivery::updatedAt => $this->_object->updatedAt,
            ObjectNotificationDelivery::deliveredAt => $this->_object->deliveredAt,
            default => parent::__get($name),
        };
    }
}
