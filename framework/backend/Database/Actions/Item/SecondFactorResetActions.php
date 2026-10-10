<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorReset as ObjectSecondFactorReset;
use Hilos\Database\View\Item\SecondFactorReset;

/**
 * SecondFactorResetActions - write operations for one delayed removal (HIL-494).
 *
 * A request ends by exactly one of {@see cancel()} and {@see complete()}; both answer
 * whether this call was the one that ended it, and {@see remind()} answers the same of its mark.
 *
 * @extends DbActions<SecondFactorReset, ObjectSecondFactorReset>
 * @property-read ObjectSecondFactorReset $object
 */
class SecondFactorResetActions extends DbActions
{
    /**
     * Cancels the request, if it still stands.
     *
     * @return bool True when this call canceled it, false when it was canceled or carried out already
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the request cannot expose its id string
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function cancel(): bool
    {
        $this->ensureCanWrite();

        return $this->object->cancel();
    }

    /**
     * Marks the request carried out, if it still stands.
     *
     * @return bool True when this call ended it, false when it was canceled or carried out already
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the request cannot expose its id string
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function complete(): bool
    {
        $this->ensureCanWrite();

        return $this->object->complete();
    }

    /**
     * Marks the request reminded now, if it still stands and was last announced no later than a bound (HIL-1406).
     *
     * Conditional, like {@see cancel()} and {@see complete()}: of two marks of the same day exactly
     * one answers true, and only that one is followed by the reminder.
     *
     * @param string $notifiedBefore An announcement at or before this moment is stale (SQL datetime)
     * @return bool True when this call marked the request, false when it no longer stands or was reminded since
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the request cannot expose its id string
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function remind(string $notifiedBefore): bool
    {
        $this->ensureCanWrite();

        return $this->object->remind($notifiedBefore);
    }
}
