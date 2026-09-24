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
use Hilos\Database\Object\Collection\SecondFactorBackupCodes as ObjectSecondFactorBackupCodes;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorBackupCodes as DbCollectionSecondFactorBackupCodes;
use Hilos\Database\View\Item\SecondFactorBackupCode;

/**
 * SecondFactorBackupCodesActions - write operations for the SecondFactorBackupCodes collection (HIL-494).
 *
 * Issuing a person's set and deleting it. Burning one code is a write on a row already
 * there and belongs to that item's actions.
 *
 * @extends DbActions<SecondFactorBackupCode, ObjectSecondFactorBackupCodes>
 * @property-read DbCollectionSecondFactorBackupCodes $collection
 * @property-read ObjectSecondFactorBackupCodes $objectCollection
 */
final class SecondFactorBackupCodesActions extends DbActions
{
    /**
     * Replaces a person's backup codes with a new set.
     *
     * @param int $userId Person the set belongs to
     * @param list<string> $codes Normalized codes of the new set
     * @throws CreateNotAllowedException When the truth source rejects the inserts
     * @throws WriteNotAllowedException When the truth source rejects the delete of the old set or a code write
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When a lookup, a delete, an insert or a code write fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If an inserted row has no primary key
     */
    public function issueSet(int $userId, array $codes): void
    {
        $this->ensureCanCreate();
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $this->objectCollection->issueSet($userId, $codes);
    }

    /**
     * Deletes a person's whole set.
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
