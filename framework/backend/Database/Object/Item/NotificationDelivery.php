<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Exception\Transaction\CrossConnectionWriteException;
use Hilos\Database\Object\Collection\NotificationDeliveries as ObjectNotificationDeliveries;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * NotificationDelivery object - wraps a NotificationDelivery entity.
 *
 * Exposes the delivery row's fields and the small status predicates the delivery
 * agent reads; the status transitions (mark sent / record a failed attempt) are
 * write operations and live on the item actions. The retry reset is the one
 * transition kept here: it judges the saved row and refreshes this object's diff
 * baseline from it, which only the object itself may do.
 *
 * @extends Object_<EntityNotificationDelivery>
 *
 * @property-read ?int $id
 * @property int $notificationId
 * @property string $channel
 * @property ?int $routingKey
 * @property string $status
 * @property int $attempts
 * @property ?string $lastError
 * @property-read int $createdAt
 * @property int $updatedAt
 * @property ?int $deliveredAt
 */
class NotificationDelivery extends Object_
{
    public const string ENTITY_CLASS = EntityNotificationDelivery::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectNotificationDeliveries::class;
    public const string id = 'id';
    public const string notificationId = 'notificationId';
    public const string channel = 'channel';
    public const string routingKey = 'routingKey';
    public const string status = 'status';
    public const string attempts = 'attempts';
    public const string lastError = 'lastError';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';
    public const string deliveredAt = 'deliveredAt';

    /**
     * Resets the saved failed row to pending, judged by the table rather than by this held object.
     *
     * The object may lag behind the table: another process may have settled, reset or removed
     * the row, or a new row may stand under the same id. So the saved row is read first, and a
     * row the table no longer holds is refused by its id. The write guard then judges the
     * proposal as it is held, so a rewritten coordinate is refused before the refresh could hide
     * it; only after that does the saved row become both the values and the diff baseline of
     * this same object, so every View holding it reads the table again and the update carries
     * the saved previous values. The status and the notification behind the row are judged on
     * the saved state, the notification by a fresh count rather than by any mirror. A refused
     * reset leaves the table as it was.
     *
     * @throws ValidationException When the saved row is gone, is not failed, or its notification is gone
     * @throws ObjectGetIdStringNotImplementedException When this object has never been saved
     * @throws DatabaseException When a read or the update query fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws CrossConnectionWriteException When another connection holds an open application level
     * @throws WriteNotAllowedException When no truth source in this process may write that row, or a coordinate is rewritten
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     */
    public function resetForRetry(): void
    {
        $id = $this->getIdString();
        // A fresh read on purpose: the held object is what may be stale.
        $saved = static::ENTITY_CLASS::get([EntityNotificationDelivery::id => $this->id])->first();
        if ($saved === null) {
            throw new ValidationException("Unknown delivery: {$id}");
        }

        Database::assertCanWrite($saved::getDatabase()->connectionIndex);
        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            $id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
            $this,
        );
        $this->restoreSaved($saved->toArray(), related: true);
        if ($saved->status !== DeliveryStatus::FAILED) {
            throw new ValidationException('Only failed deliveries can be retried');
        }
        if (EntityNotification::countUpTo(1, [EntityNotification::id => $saved->notification_id]) === 0) {
            throw new ValidationException('Delivery notification is unavailable');
        }

        $this->status = DeliveryStatus::PENDING;
        $this->attempts = 0;
        $this->lastError = null;
        $this->deliveredAt = null;
        $this->updatedAt = TimeHelper::nowMs();
        $this->sync();
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Delivery scalar field name
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known NotificationDelivery field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::notificationId => $this->entity->notification_id,
            self::channel => $this->entity->channel,
            self::routingKey => $this->entity->routing_key,
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
     * @param string $property Writable delivery scalar field name
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a NotificationDelivery
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::notificationId => $this->entity->notification_id = (int)$value,
            self::channel => $this->entity->channel = (string)$value,
            self::routingKey => $this->entity->routing_key = $value === null ? null : (int)$value,
            self::status => $this->entity->status = (string)$value,
            self::attempts => $this->entity->attempts = (int)$value,
            self::lastError => $this->entity->last_error = $value === null ? null : (string)$value,
            self::createdAt => $this->entity->created_at = (int)$value,
            self::updatedAt => $this->entity->updated_at = (int)$value,
            self::deliveredAt => $this->entity->delivered_at = $value === null ? null : (int)$value,
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
     * @return array<string, mixed> Delivery scalar fields, including the nullable original routing dimension
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::notificationId => $this->entity->notification_id,
            self::channel => $this->entity->channel,
            self::routingKey => $this->entity->routing_key,
            self::status => $this->entity->status,
            self::attempts => $this->entity->attempts,
            self::lastError => $this->entity->last_error,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            self::deliveredAt => $this->entity->delivered_at,
        ];
    }
}
