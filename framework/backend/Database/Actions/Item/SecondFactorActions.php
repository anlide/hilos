<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactor as ObjectSecondFactor;
use Hilos\Database\View\Item\SecondFactor;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorActions - write operations for one authenticator app (HIL-494).
 *
 * @extends DbActions<SecondFactor, ObjectSecondFactor>
 * @property-read ObjectSecondFactor $object
 */
final class SecondFactorActions extends DbActions
{
    /**
     * Finishes the enrolment: the authenticator becomes part of the person's second factor.
     *
     * @param string $label Name the person gave the authenticator
     * @throws ItemNotFoundForUpdateException When the authenticator is not persisted (id is null)
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the authenticator cannot expose its id string
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the sync
     * @throws DatabaseException When the update fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function confirm(string $label): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Second factor not found for confirm (id is null)');
        }

        $this->object->label = $label;
        $this->object->confirmedAt = TimeHelper::getSqlDateTime();
        $this->object->sync();
    }

    /**
     * Takes a code's time step for this authenticator, if no later or equal step was taken before.
     *
     * @param int $step Time step the code matched
     * @return bool True when this call took the step, false when it was taken already
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the authenticator cannot expose its id string
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function acceptStep(int $step): bool
    {
        $this->ensureCanWrite();

        return $this->object->acceptStep($step);
    }

    /**
     * Removes this authenticator.
     *
     * @throws ItemNotFoundForDeleteException When the authenticator is not persisted (id is null)
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the authenticator cannot expose its id string
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws DatabaseException When the delete fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Second factor not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}
