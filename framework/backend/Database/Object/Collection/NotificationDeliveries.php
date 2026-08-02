<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\NotificationDeliveries as EntityNotificationDeliveries;
use Hilos\Database\Entity\Item\NotificationDelivery as EntityNotificationDelivery;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Object\Item\NotificationDelivery as ObjectNotificationDelivery;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\SqlSortDirection;
use Hilos\Notification\Delivery\DeliveryStatus;
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
final class NotificationDeliveries extends Objects
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
     * @return ObjectNotificationDelivery The created delivery object
     * @throws EmptyValueException When the channel name is empty
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException If the insert query fails
     */
    public function createPending(int $notificationId, string $channel): ObjectNotificationDelivery
    {
        $this->requireActivatedTable();

        if ($channel === '') {
            throw new EmptyValueException('Notification delivery channel is required');
        }

        $delivery = ObjectNotificationDelivery::create();
        $delivery->notificationId = $notificationId;
        $delivery->channel = $channel;
        $delivery->status = DeliveryStatus::PENDING;
        $delivery->attempts = 0;
        $delivery->createdAt = TimeHelper::getSqlDateTime();
        $delivery->updatedAt = $delivery->createdAt;
        $delivery->sync();

        $id = $delivery->id;
        if ($id === null) {
            throw new DatabaseException('Notification delivery insert did not assign an id');
        }

        $this->objects[$id] = $delivery;

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
     */
    public function findFor(int $notificationId, string $channel): ?ObjectNotificationDelivery
    {
        $this->requireActivatedTable();

        $entities = EntityNotificationDelivery::get(
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
                $this->objects[$entity->id] = ObjectNotificationDelivery::fromEntity($entity);
            }

            return $this->objects[$entity->id];
        }

        return null;
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
     */
    public function beginAttempt(ObjectNotificationDelivery $delivery): void
    {
        $delivery->attempts = $delivery->attempts + 1;
        $delivery->updatedAt = TimeHelper::getSqlDateTime();
        $delivery->sync();
    }

    /**
     * Marks a delivery sent, stamping the delivered time.
     *
     * @param ObjectNotificationDelivery $delivery Loaded delivery row
     * @throws DatabaseException If the update query fails
     */
    public function markSent(ObjectNotificationDelivery $delivery): void
    {
        $now = TimeHelper::getSqlDateTime();
        $delivery->status = DeliveryStatus::SENT;
        $delivery->deliveredAt = $now;
        $delivery->updatedAt = $now;
        $delivery->sync();
    }

    /**
     * Loads a delivery row by its primary id, or null when absent.
     *
     * The admin retry re-entry point (HIL-201): the retry action carries the delivery
     * id, and this resolves the single row to reset and re-queue.
     *
     * @param int $id Delivery row id
     * @return ?ObjectNotificationDelivery The delivery object, or null when none exists
     * @throws TableNotActivatedException When the project has not activated the delivery table
     * @throws DatabaseException If the database query fails
     */
    public function findById(int $id): ?ObjectNotificationDelivery
    {
        if (isset($this->objects[$id])) {
            return $this->objects[$id];
        }

        $this->requireActivatedTable();

        $entity = EntityNotificationDelivery::getById($id);
        if ($entity === null || $entity->id === null) {
            return null;
        }

        $this->objects[$entity->id] = ObjectNotificationDelivery::fromEntity($entity);

        return $this->objects[$entity->id];
    }

    /**
     * Resets a failed delivery back to pending for a fresh channel attempt (HIL-201).
     *
     * Clears the terminal state so the channel agent that receives the re-queued
     * deliver signal drives the row anew: status to pending, attempts to zero, and
     * the last error and delivered time cleared.
     *
     * @param ObjectNotificationDelivery $delivery Loaded failed delivery row
     * @throws DatabaseException If the update query fails
     */
    public function resetForRetry(ObjectNotificationDelivery $delivery): void
    {
        $delivery->status = DeliveryStatus::PENDING;
        $delivery->attempts = 0;
        $delivery->lastError = null;
        $delivery->deliveredAt = null;
        $delivery->updatedAt = TimeHelper::getSqlDateTime();
        $delivery->sync();
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
     */
    public function recordFailure(ObjectNotificationDelivery $delivery, string $error, int $maxAttempts): void
    {
        $delivery->lastError = mb_substr($error, 0, self::LAST_ERROR_LIMIT);
        if ($delivery->attempts >= $maxAttempts) {
            $delivery->status = DeliveryStatus::FAILED;
        }
        $delivery->updatedAt = TimeHelper::getSqlDateTime();
        $delivery->sync();
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
     */
    private function requireActivatedTable(): void
    {
        if ($this->tableActivationConfirmed) {
            return;
        }

        Schema::requireTable(EntityNotificationDelivery::_table);
        $this->tableActivationConfirmed = true;
    }
}
