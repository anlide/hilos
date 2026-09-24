<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorReset as ObjectSecondFactorReset;
use Hilos\Database\View\Item\SecondFactorReset;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorResetActions - write operations for one delayed removal (HIL-494).
 *
 * A request ends by exactly one of {@see cancel()} and {@see complete()}; both answer
 * whether this call was the one that ended it.
 *
 * @extends DbActions<SecondFactorReset, ObjectSecondFactorReset>
 * @property-read ObjectSecondFactorReset $object
 */
final class SecondFactorResetActions extends DbActions
{
    /**
     * Cancels the request, if it still stands.
     *
     * @return bool True when this call canceled it, false when it was canceled or carried out already
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the request cannot expose its id string
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
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
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
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
     * Stamps the moment the request was last announced.
     *
     * @throws ItemNotFoundForUpdateException When the request is not persisted (id is null)
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the request cannot expose its id string
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the sync
     * @throws DatabaseException When the update fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function markNotified(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Second factor reset not found for markNotified (id is null)');
        }

        $this->object->notifiedAt = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }
}
