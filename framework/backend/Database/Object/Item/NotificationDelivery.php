<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Object\Item\Object_;
use Hilos\Notification\Delivery\DeliveryStatus;

/**
 * NotificationDelivery object - wraps a NotificationDelivery entity.
 *
 * Exposes the delivery row's fields and the small status predicates the delivery
 * agent reads; the status transitions (mark sent / record a failed attempt) are
 * write operations and live on the item actions.
 *
 * @extends Object_<EntityNotificationDelivery>
 *
 * @property-read ?int $id
 * @property int $notificationId
 * @property string $channel
 * @property string $status
 * @property int $attempts
 * @property ?string $lastError
 * @property-read string $createdAt
 * @property string $updatedAt
 * @property ?string $deliveredAt
 */
final class NotificationDelivery extends Object_
{
    public const string ENTITY_CLASS = EntityNotificationDelivery::class;
    public const string id = 'id';
    public const string notificationId = 'notificationId';
    public const string channel = 'channel';
    public const string status = 'status';
    public const string attempts = 'attempts';
    public const string lastError = 'lastError';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';
    public const string deliveredAt = 'deliveredAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::notificationDeliveries)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::notificationDeliveries;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, notificationId, channel, status, attempts, lastError, createdAt, updatedAt, deliveredAt)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known NotificationDelivery field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::notificationId => $this->entity->notification_id,
            self::channel => $this->entity->channel,
            self::status => $this->entity->status,
            self::attempts => $this->entity->attempts,
            self::lastError => $this->entity->last_error,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            self::deliveredAt => $this->entity->delivered_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (notificationId, channel, status, attempts, lastError, createdAt, updatedAt, deliveredAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a NotificationDelivery
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::notificationId => $this->entity->notification_id = (int)$value,
            self::channel => $this->entity->channel = (string)$value,
            self::status => $this->entity->status = (string)$value,
            self::attempts => $this->entity->attempts = (int)$value,
            self::lastError => $this->entity->last_error = $value === null ? null : (string)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            self::updatedAt => $this->entity->updated_at = (string)$value,
            self::deliveredAt => $this->entity->delivered_at = $value === null ? null : (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Whether this delivery is still awaiting the channel agent.
     *
     * @return bool True when the status is pending
     */
    public function isPending(): bool
    {
        return $this->entity->status === DeliveryStatus::PENDING;
    }

    /**
     * Whether this delivery has reached a terminal state (sent or failed).
     *
     * @return bool True when the status is sent or failed
     */
    public function isSettled(): bool
    {
        return $this->entity->status === DeliveryStatus::SENT
            || $this->entity->status === DeliveryStatus::FAILED;
    }

    /**
     * Converts the delivery to an associative array.
     *
     * @return array<string, mixed> Delivery data (id, notificationId, channel, status, attempts, lastError, createdAt, updatedAt, deliveredAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::notificationId => $this->entity->notification_id,
            self::channel => $this->entity->channel,
            self::status => $this->entity->status,
            self::attempts => $this->entity->attempts,
            self::lastError => $this->entity->last_error,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            self::deliveredAt => $this->entity->delivered_at,
        ];
    }
}
