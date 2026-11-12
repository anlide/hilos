<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\ChangeLog\Section\ChangeLogReadCollection;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\NotificationDeliveries as EntityNotificationDeliveries;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Exception\Transaction\CrossConnectionWriteException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;
use Hilos\Notification\Delivery\DeliveryStatus;
use Hilos\Notification\Delivery\ExpiredDeliveryRef;
use Hilos\Notification\Delivery\NotificationDeliveryIdentity;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * NotificationDeliveries object collection.
 *
 * Persistence primitives of channel delivery (HIL-196): the dispatcher creates one
 * pending row per resolved channel through {@see createPending()}, and a channel's
 * delivery agent reloads the row for a notification through {@see findFor()} to move
 * it to sent/failed (the status transitions live on {@see ObjectNotificationDelivery}
 * via its item actions). One row exists per (notification, channel) pair.
 *
 * @extends Objects<ObjectNotificationDelivery>
 * @method ObjectNotificationDelivery|null current()
 * @method ObjectNotificationDelivery|null first()
 * @method ObjectNotificationDelivery|null last()
 * @method ObjectNotificationDelivery|null get(int|string $key)
 * @method ObjectNotificationDelivery|null offsetGet(mixed $offset)
 */
class NotificationDeliveries extends Objects
{
    public const string OBJECT_CLASS = ObjectNotificationDelivery::class;
    public const string ENTITY_COLLECTION_CLASS = EntityNotificationDeliveries::class;
    public const string COLLECTION_KEY = HilosDbContext::notificationDeliveries;

    /** Longest failure detail kept in `last_error` (column is VARCHAR(255)). */
    private const int LAST_ERROR_LIMIT = 255;

    /** Whether the activation of the delivery table has already been confirmed. */
    private bool $tableActivationConfirmed = false;

    /**
     * Persists a pending delivery row for one channel of one notification.
     *
     * The durable side of the dispatch fan: the row is inserted `pending` with zero
     * attempts so the channel agent that receives the matching deliver signal can
     * find it and drive it to a terminal state.
     *
     * @param int $notificationId Notification being delivered (soft ref)
     * @param string $channel Target channel name
     * @param ?int $routingKey Original nonnegative routing dimension, null for legacy callers
     * @return ObjectNotificationDelivery The created delivery object
     * @throws EmptyValueException When the channel name is empty
     * @throws ValidationException When the routing key is negative
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException If the insert query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When ownership or the insert announcement refuses the row
     */
    public function createPending(
        int $notificationId,
        string $channel,
        ?int $routingKey = null,
    ): ObjectNotificationDelivery {
        $this->requireActivatedTable();

        if ($channel === '') {
            throw new EmptyValueException('Notification delivery channel is required');
        }
        if ($routingKey !== null && $routingKey < 0) {
            throw new ValidationException('Notification delivery routing key must be nonnegative');
        }

        $delivery = static::OBJECT_CLASS::create();
        $delivery->notificationId = $notificationId;
        $delivery->channel = $channel;
        $delivery->routingKey = $routingKey;
        $delivery->status = DeliveryStatus::PENDING;
        $delivery->attempts = 0;
        $delivery->createdAt = TimeHelper::nowMs();
        $delivery->updatedAt = $delivery->createdAt;
        $delivery->sync();

        $id = $delivery->id;
        if ($id === null) {
            throw new DatabaseException('Notification delivery insert did not assign an id');
        }

        $this[$id] = $delivery;

        return $delivery;
    }

    /**
     * Loads the delivery row for one channel of one notification, or null when absent.
     *
     * The channel agent's re-entry point: the deliver signal carries the notification
     * id and channel name, and this resolves the single (notification, channel) row to
     * update.
     *
     * @param int $notificationId Notification id
     * @param string $channel Channel name
     * @return ?ObjectNotificationDelivery The delivery object, or null when none exists
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findFor(int $notificationId, string $channel): ?ObjectNotificationDelivery
    {
        $this->requireActivatedTable();

        $entities = static::entityClass()::get( // fresh-sql: select the persisted pair even when a held object's identity is dirty.
            [
                EntityNotificationDelivery::notification_id => $notificationId,
                EntityNotificationDelivery::channel => $channel,
            ],
            [],
            [EntityNotificationDelivery::id => SqlSortDirection::ASC],
        );

        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
            }

            return $this->objects[$entity->id];
        }

        return null;
    }

    /**
     * Reads saved coordinates without hydrating or consulting the local Object mirror.
     *
     * @param int $deliveryId Delivery primary key
     * @return ?NotificationDeliveryIdentity Fresh saved identity, null when the delivery has disappeared
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the binding or projection query fails
     */
    public function readIdentity(int $deliveryId): ?NotificationDeliveryIdentity
    {
        $this->requireActivatedTable();
        $entityClass = static::entityClass();
        $row = Database::sql(
            'SELECT `' . EntityNotificationDelivery::id . '`, `' . EntityNotificationDelivery::notification_id . '`,'
            . ' `' . EntityNotificationDelivery::channel . '`, `' . EntityNotificationDelivery::routing_key . '`'
            . ' FROM ' . $entityClass::getSqlTableName()
            . ' WHERE `' . EntityNotificationDelivery::id . '` = ? LIMIT 1',
            [$deliveryId],
            index: $entityClass::getDatabase()->connectionIndex,
        )->firstRow();
        if ($row === null) {
            return null;
        }

        return new NotificationDeliveryIdentity(
            deliveryId: (int)$row[EntityNotificationDelivery::id],
            notificationId: (int)$row[EntityNotificationDelivery::notification_id],
            channel: (string)$row[EntityNotificationDelivery::channel],
            routingKey: $row[EntityNotificationDelivery::routing_key] === null
                ? null : (int)$row[EntityNotificationDelivery::routing_key],
        );
    }

    /**
     * Reads one bounded page of saved pending identities, including orphaned deliveries.
     *
     * @param string $channel Saved channel to scan
     * @param int $afterId Exclusive delivery primary-key cursor
     * @param int $limit Positive maximum number of identities
     * @return ChangeLogReadCollection<NotificationDeliveryIdentity> Dense snapshot in ascending delivery-id order
     * @throws InvalidArgumentException When the page limit is not positive
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the binding or projection query fails
     */
    public function pendingIdentitiesAfter(string $channel, int $afterId, int $limit): ChangeLogReadCollection
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Notification delivery identity page limit must be positive');
        }
        $this->requireActivatedTable();
        $entityClass = static::entityClass();
        $identities = [];
        foreach (Database::sql(
            'SELECT `' . EntityNotificationDelivery::id . '`, `' . EntityNotificationDelivery::notification_id . '`,'
            . ' `' . EntityNotificationDelivery::channel . '`, `' . EntityNotificationDelivery::routing_key . '`'
            . ' FROM ' . $entityClass::getSqlTableName()
            . ' WHERE `' . EntityNotificationDelivery::channel . '` = ?'
            . ' AND `' . EntityNotificationDelivery::status . '` = ?'
            . ' AND `' . EntityNotificationDelivery::id . '` > ?'
            . ' ORDER BY `' . EntityNotificationDelivery::id . '` ASC LIMIT ?',
            [$channel, DeliveryStatus::PENDING, $afterId, $limit],
            index: $entityClass::getDatabase()->connectionIndex,
        )->rows() as $row) {
            $identities[] = new NotificationDeliveryIdentity(
                deliveryId: (int)$row[EntityNotificationDelivery::id],
                notificationId: (int)$row[EntityNotificationDelivery::notification_id],
                channel: (string)$row[EntityNotificationDelivery::channel],
                routingKey: $row[EntityNotificationDelivery::routing_key] === null
                    ? null : (int)$row[EntityNotificationDelivery::routing_key],
            );
        }

        return ChangeLogReadCollection::fromArray($identities);
    }

    /**
     * Reads one bounded page of saved identities of a recipient's deliveries, whatever their status.
     * The journal carries no recipient of its own, so the rows are found through their notifications.
     *
     * @param int $recipientUserId Recipient whose notifications the deliveries belong to
     * @param int $afterId Exclusive delivery primary-key cursor
     * @param int $limit Positive maximum number of identities
     * @return ChangeLogReadCollection<NotificationDeliveryIdentity> Dense snapshot in ascending delivery-id order
     * @throws InvalidArgumentException When the page limit is not positive
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the binding or projection query fails
     */
    public function recipientIdentitiesAfter(int $recipientUserId, int $afterId, int $limit): ChangeLogReadCollection
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Notification delivery identity page limit must be positive');
        }
        $this->requireActivatedTable();
        $entityClass = static::entityClass();
        $identities = [];
        foreach (Database::sql( // fresh-sql: the erasure census pages a recipient's saved deliveries by id, which the mirror cannot index.
            'SELECT `' . EntityNotificationDelivery::id . '`, `' . EntityNotificationDelivery::notification_id . '`,'
            . ' `' . EntityNotificationDelivery::channel . '`, `' . EntityNotificationDelivery::routing_key . '`'
            . ' FROM ' . $entityClass::getSqlTableName()
            . ' WHERE `' . EntityNotificationDelivery::notification_id . '` IN (SELECT `' . EntityNotification::id
            . '` FROM `' . EntityNotification::_table . '` WHERE `' . EntityNotification::user_id . '` = ?)'
            . ' AND `' . EntityNotificationDelivery::id . '` > ?'
            . ' ORDER BY `' . EntityNotificationDelivery::id . '` ASC LIMIT ?',
            [$recipientUserId, $afterId, $limit],
            index: $entityClass::getDatabase()->connectionIndex,
        )->rows() as $row) {
            $identities[] = new NotificationDeliveryIdentity(
                deliveryId: (int)$row[EntityNotificationDelivery::id],
                notificationId: (int)$row[EntityNotificationDelivery::notification_id],
                channel: (string)$row[EntityNotificationDelivery::channel],
                routingKey: $row[EntityNotificationDelivery::routing_key] === null
                    ? null : (int)$row[EntityNotificationDelivery::routing_key],
            );
        }

        return ChangeLogReadCollection::fromArray($identities);
    }

    /**
     * Reads a bounded saved snapshot without consulting the mutable Object mirror.
     *
     * @param string $channel Saved channel to scan
     * @param int $cutoffMs Exclusive creation-age boundary in epoch milliseconds
     * @param int $afterCreatedAt Creation moment of the exclusive cursor in epoch milliseconds
     * @param int $afterId Delivery primary key breaking ties at the cursor moment
     * @param int $limit Positive maximum number of references
     * @return ChangeLogReadCollection<ExpiredDeliveryRef> Dense snapshot ordered by creation moment, then delivery id
     * @throws InvalidArgumentException When the page limit is not positive
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the binding or projection query fails
     */
    public function expiredIdentitiesAfter(
        string $channel,
        int $cutoffMs,
        int $afterCreatedAt,
        int $afterId,
        int $limit,
    ): ChangeLogReadCollection {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Expired notification delivery page limit must be positive');
        }
        $this->requireActivatedTable();
        $entityClass = static::entityClass();
        $references = [];
        foreach (Database::sql(
            'SELECT `' . EntityNotificationDelivery::id . '`, `' . EntityNotificationDelivery::notification_id . '`,'
            . ' `' . EntityNotificationDelivery::channel . '`, `' . EntityNotificationDelivery::routing_key . '`,'
            . ' `' . EntityNotificationDelivery::created_at . '`'
            . ' FROM ' . $entityClass::getSqlTableName()
            . ' WHERE `' . EntityNotificationDelivery::channel . '` = ?'
            . ' AND `' . EntityNotificationDelivery::status . '` IN (?, ?)'
            . ' AND `' . EntityNotificationDelivery::created_at . '` < ?'
            . ' AND (`' . EntityNotificationDelivery::created_at . '` > ?'
            . ' OR (`' . EntityNotificationDelivery::created_at . '` = ? AND `' . EntityNotificationDelivery::id . '` > ?))'
            . ' ORDER BY `' . EntityNotificationDelivery::created_at . '` ASC, `' . EntityNotificationDelivery::id . '` ASC LIMIT ?',
            [$channel, DeliveryStatus::SENT, DeliveryStatus::FAILED, $cutoffMs, $afterCreatedAt, $afterCreatedAt, $afterId, $limit],
            index: $entityClass::getDatabase()->connectionIndex,
        )->rows() as $row) {
            $references[] = new ExpiredDeliveryRef(
                identity: new NotificationDeliveryIdentity(
                    deliveryId: (int)$row[EntityNotificationDelivery::id],
                    notificationId: (int)$row[EntityNotificationDelivery::notification_id],
                    channel: (string)$row[EntityNotificationDelivery::channel],
                    routingKey: $row[EntityNotificationDelivery::routing_key] === null
                        ? null : (int)$row[EntityNotificationDelivery::routing_key],
                ),
                createdAt: (int)$row[EntityNotificationDelivery::created_at],
            );
        }

        return ChangeLogReadCollection::fromArray($references);
    }

    /**
     * Counts one delivery attempt against the row, keeping it pending.
     *
     * Called by the channel agent when it starts an attempt, so `attempts` reflects
     * the number of tries made when {@see recordFailure()} later decides whether to
     * retry or fail terminally.
     *
     * @param ObjectNotificationDelivery $delivery Loaded delivery row
     * @throws DatabaseException If the update query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function beginAttempt(ObjectNotificationDelivery $delivery): void
    {
        $delivery->attempts = $delivery->attempts + 1;
        $delivery->updatedAt = TimeHelper::nowMs();
        $delivery->sync();
    }

    /**
     * Marks a delivery sent, stamping the delivered time.
     *
     * @param ObjectNotificationDelivery $delivery Loaded delivery row
     * @throws DatabaseException If the update query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function markSent(ObjectNotificationDelivery $delivery): void
    {
        $now = TimeHelper::nowMs();
        $delivery->status = DeliveryStatus::SENT;
        $delivery->deliveredAt = $now;
        $delivery->updatedAt = $now;
        $delivery->sync();
    }

    /**
     * Resets a failed delivery back to pending for a fresh channel attempt (HIL-201).
     *
     * Clears the terminal state so the channel agent drives the row anew: status to
     * pending, attempts to zero, and the last error and delivered time cleared. The
     * row is judged as the table holds it, not as the loaded object remembers it, and
     * the object's diff baseline is refreshed from the saved row before the update
     * ({@see ObjectNotificationDelivery::resetForRetry()}).
     *
     * @param ObjectNotificationDelivery $delivery Loaded delivery row, possibly behind the table
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws ValidationException When the saved row is gone, is not failed, or its notification is gone
     * @throws ObjectGetIdStringNotImplementedException When the delivery has never been saved
     * @throws DatabaseException When a read or the update query fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws CrossConnectionWriteException When another connection holds an open application level
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row, or a coordinate is rewritten
     */
    public function resetForRetry(ObjectNotificationDelivery $delivery): void
    {
        $this->requireActivatedTable();
        $delivery->resetForRetry();
    }

    /**
     * Records a failed attempt, failing the row terminally once retries are exhausted.
     *
     * The bounded-retry decision: the row keeps its `pending` status (retried on a
     * later tick) while `attempts` is below the ceiling, and flips to `failed` once
     * it reaches it. The failure detail is truncated to the `last_error` column width.
     *
     * @param ObjectNotificationDelivery $delivery Loaded delivery row
     * @param string $error Failure detail (truncated to the column width)
     * @param int $maxAttempts Attempt ceiling after which the row fails terminally
     * @throws DatabaseException If the update query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function recordFailure(ObjectNotificationDelivery $delivery, string $error, int $maxAttempts): void
    {
        $delivery->lastError = mb_substr($error, 0, self::LAST_ERROR_LIMIT);
        if ($delivery->attempts >= $maxAttempts) {
            $delivery->status = DeliveryStatus::FAILED;
        }
        $delivery->updatedAt = TimeHelper::nowMs();
        $delivery->sync();
    }

    /**
     * Deletes the delivery journal of every notification of a recipient (HIL-302).
     *
     * The account is being erased, and its notifications go right after this. The journal
     * carries no recipient of its own, so the rows are found through the notification each
     * belongs to. Each row leaves through its object so a delete announcement reaches every
     * reader - the administrator's journal among them.
     *
     * @param int $userId Recipient user id
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForRecipient(int $userId): void
    {
        $this->requireActivatedTable();

        $where = '`' . EntityNotificationDelivery::notification_id . '` IN (SELECT `' . EntityNotification::id
            . '` FROM `' . EntityNotification::_table . '` WHERE `' . EntityNotification::user_id . '` = ?)';
        foreach (static::entityClass()::get($where, [$userId]) as $entity) { // fresh-sql: saved parent links decide erasure membership.
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $this->objects[$id]->delete();
            unset($this[$id]);
        }
    }

    /**
     * Asserts once per collection that the project activated the delivery table.
     *
     * The schema is loaded once per process, so a confirmed activation cannot become
     * false again and the check is remembered instead of repeated on every dispatch.
     * Only success is remembered: a project that activates the table later in the same
     * process (a migration run followed by a fresh schema load) is picked up by the
     * next call.
     *
     * @throws TableNotActivatedException When the project has not activated the table
     * @throws DatabaseException When the mounted Entity's binding cannot be resolved
     */
    private function requireActivatedTable(): void
    {
        if ($this->tableActivationConfirmed) {
            return;
        }

        $entityClass = static::entityClass();
        Schema::requireTable($entityClass::_table, $entityClass::getDatabase()->schemaIndex);
        $this->tableActivationConfirmed = true;
    }
}
