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
use Hilos\Database\Object\Collection\SecondFactorTrusts as ObjectSecondFactorTrusts;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorTrusts as DbCollectionSecondFactorTrusts;
use Hilos\Database\View\Item\SecondFactorTrust;

/**
 * SecondFactorTrustsActions - write operations for the SecondFactorTrusts collection (HIL-494).
 *
 * Trusting a browser for a person, and taking every trust of a person out.
 *
 * @extends DbActions<SecondFactorTrust, ObjectSecondFactorTrusts>
 * @property-read DbCollectionSecondFactorTrusts $collection
 * @property-read ObjectSecondFactorTrusts $objectCollection
 */
final class SecondFactorTrustsActions extends DbActions
{
    /**
     * Trusts a browser for a person until a moment, writing the pair or moving its end.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param string $until Moment the trust runs out (SQL datetime)
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function trust(int $sessionId, int $userId, string $until): void
    {
        $this->ensureCanCreate();

        $this->objectCollection->trust($sessionId, $userId, $until);
    }

    /**
     * Deletes every trust of a person.
     *
     * @param int $userId Person
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When a lookup or a delete fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $this->objectCollection->deleteForUser($userId);
    }
}
