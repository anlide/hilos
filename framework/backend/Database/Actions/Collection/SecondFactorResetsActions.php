<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorResets as ObjectSecondFactorResets;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorResets as DbCollectionSecondFactorResets;
use Hilos\Database\View\Item\SecondFactorReset;

/**
 * SecondFactorResetsActions - write operations for the SecondFactorResets collection (HIL-494).
 *
 * Opening a delayed removal. Cancelling it, carrying it out and stamping an announcement
 * are writes on a row already there and belong to that item's actions.
 *
 * @extends DbActions<SecondFactorReset, ObjectSecondFactorResets>
 * @property-read DbCollectionSecondFactorResets $collection
 * @property-read ObjectSecondFactorResets $objectCollection
 */
final class SecondFactorResetsActions extends DbActions
{
    /**
     * Opens a delayed removal of a person's second factor.
     *
     * @param int $userId Person whose second factor is to be removed
     * @param string $effectiveAt Moment the removal is carried out (SQL datetime)
     * @param string $cancelTokenHash sha256 (hex) of the token the cancel link carries
     * @return SecondFactorReset The request
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the insert fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     */
    public function request(int $userId, string $effectiveAt, string $cancelTokenHash): SecondFactorReset
    {
        $this->ensureCanCreate();

        return $this->createDbItemFromObject(
            $this->objectCollection->request($userId, $effectiveAt, $cancelTokenHash),
        );
    }

    /**
     * Deletes every delayed removal of a person - the account is being erased (HIL-302).
     *
     * @param int $userId Person
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
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
