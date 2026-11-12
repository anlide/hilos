<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorSettings as ObjectSecondFactorSettings;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorSettings as DbCollectionSecondFactorSettings;
use Hilos\Database\View\Item\SecondFactorSetting;

/**
 * SecondFactorSettingsActions - write operations for the SecondFactorSettings collection (HIL-494).
 *
 * The writes are collection ones even when the row turns out to exist: a person's row is
 * created on the first choice they make, or on their first wrong app code (HIL-1285), and the
 * lock that code count puts and lifts lives on the same row.
 *
 * @extends DbActions<SecondFactorSetting, ObjectSecondFactorSettings>
 * @property-read DbCollectionSecondFactorSettings $collection
 * @property-read ObjectSecondFactorSettings $objectCollection
 */
class SecondFactorSettingsActions extends DbActions
{
    /**
     * Stores a person's removal wait: the one in force and a shorter one parked until a moment.
     *
     * @param int $userId Person
     * @param ?int $days Wait in force in days, or null for the administrator's default
     * @param ?int $pendingDays Shorter wait parked, or null when none is
     * @param ?int $pendingFrom Moment the parked wait takes over (epoch milliseconds), or null when none is parked
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     */
    public function setResetWait(int $userId, ?int $days, ?int $pendingDays, ?int $pendingFrom): void
    {
        $this->ensureCanCreateInSet((string)$userId);

        $this->objectCollection->setResetWait($userId, $days, $pendingDays, $pendingFrom);
    }

    /**
     * Counts one wrong app code against a person and answers the count of the window (HIL-1285).
     *
     * @param int $userId Person
     * @param int $windowSeconds Length of the window the misses are counted in
     * @return int Wrong app codes in the window after this one
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the lookup, the insert, the update or the read-back fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     */
    public function countAppCodeMiss(int $userId, int $windowSeconds): int
    {
        $this->ensureCanCreateInSet((string)$userId);

        return $this->objectCollection->countAppCodeMiss($userId, $windowSeconds);
    }

    /**
     * Locks a person's app codes until a moment, if their window still holds the ceiling (HIL-1285).
     *
     * Of two misses that both reached the ceiling exactly one puts the lock; the other is
     * answered false and sends no notice of its own.
     *
     * @param int $userId Person
     * @param int $atMisses Ceiling the window has to hold for the lock to be put
     * @param int $step Step of the lock on the ladder
     * @param int $until End of the lock (epoch milliseconds)
     * @return bool True when this call put the lock, false when another miss put it already or the person has no row
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the lookup, the update, the row count or the re-announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     */
    public function lockAppCodes(int $userId, int $atMisses, int $step, int $until): bool
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);

        return $this->objectCollection->lockAppCodes($userId, $atMisses, $step, $until);
    }

    /**
     * Lifts a person's app-code lock with its step, the miss count and its window (HIL-1285).
     *
     * @param int $userId Person
     * @return ?int End of the lifted lock (epoch milliseconds) when it was still in force, or null when none was
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the sync
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the row has no primary key
     */
    public function unlockAppCodes(int $userId): ?int
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);

        return $this->objectCollection->unlockAppCodes($userId);
    }

    /**
     * Deletes every own removal wait of a person - the account is being erased (HIL-302).
     *
     * @param int $userId Person
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        $this->objectCollection->deleteForUser($userId);
    }
}
