<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StepUps as ObjectStepUps;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\StepUps as DbCollectionStepUps;
use Hilos\Database\View\Item\StepUp;

/**
 * StepUpsActions - writes of operation-level confirmations (HIL-495).
 *
 * @extends DbActions<StepUp, ObjectStepUps>
 * @property-read DbCollectionStepUps $collection
 * @property-read ObjectStepUps $objectCollection
 */
final class StepUpsActions extends DbActions
{
    /**
     * Creates or extends one browser's confirmation of one operation for a person.
     *
     * @param string $tokenHash Hash of the browser session token
     * @param int $userId Person confirming the operation
     * @param string $operation Declared operation key
     * @param string $until Moment the confirmation expires (SQL datetime)
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the set update
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection metadata is incomplete
     * @throws DatabaseException When the lookup or write fails
     * @throws InvalidArgumentException When a query or queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If an inserted row has no primary key
     */
    public function confirm(string $tokenHash, int $userId, string $operation, string $until): void
    {
        $this->ensureCanCreate();
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);

        $this->objectCollection->confirm($tokenHash, $userId, $operation, $until);
    }

    /**
     * Deletes a person's expired confirmations.
     *
     * @param int $userId Person whose expired confirmations are removed
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection metadata is incomplete
     * @throws DatabaseException When the lookup or delete fails
     * @throws InvalidArgumentException When a query or queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteExpiredForUser(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        $this->objectCollection->deleteExpiredForUser($userId);
    }
}
