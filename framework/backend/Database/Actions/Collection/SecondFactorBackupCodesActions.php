<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorBackupCodes as ObjectSecondFactorBackupCodes;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorBackupCodes as DbCollectionSecondFactorBackupCodes;
use Hilos\Database\View\Item\SecondFactorBackupCode;

/**
 * SecondFactorBackupCodesActions - write operations for the SecondFactorBackupCodes collection (HIL-494).
 *
 * Issuing a person's set - only creating it - and deleting it: the person's agent drops the old
 * set on the frame of the users library before a new one is issued (HIL-1904). Burning one code
 * is a write on a row already there and belongs to that item's actions.
 *
 * @extends DbActions<SecondFactorBackupCode, ObjectSecondFactorBackupCodes>
 * @property-read DbCollectionSecondFactorBackupCodes $collection
 * @property-read ObjectSecondFactorBackupCodes $objectCollection
 */
class SecondFactorBackupCodesActions extends DbActions
{
    /**
     * Creates a person's set of backup codes.
     *
     * Only creates: the person's old set was dropped by the person's agent before this is called
     * (HIL-1904).
     *
     * @param int $userId Person the set belongs to
     * @param list<string> $codes Normalized codes of the new set
     * @throws CreateNotAllowedException When the truth source rejects the inserts
     * @throws WriteNotAllowedException When the truth source rejects a code write
     * @throws DatabaseException When an insert or a code write fails
     * @throws InvalidArgumentException When the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If an inserted row has no primary key
     */
    public function issueSet(int $userId, array $codes): void
    {
        $this->ensureCanCreateInSet((string)$userId);

        $this->objectCollection->issueSet($userId, $codes);
    }

    /**
     * Moves backup codes, preserving their spent status inside the account-merge transaction.
     *
     * @param int $fromUserId Folded account
     * @param int $toUserId Surviving account
     * @throws WriteNotAllowedException When the caller cannot update both sets
     * @throws CreateNotAllowedException Never for persisted rows; declared by sync
     * @throws DatabaseException When reading or moving a row fails
     * @throws InvalidArgumentException When a query or sync frame is invalid
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws ObjectGetIdStringNotImplementedException When a moved row has no key
     */
    public function rePointToUser(int $fromUserId, int $toUserId): void
    {
        $this->ensureCanWriteSet((string)$fromUserId, TruthSourceOperation::Update);
        $this->ensureCanWriteSet((string)$toUserId, TruthSourceOperation::Update);

        $this->objectCollection->rePointToUser($fromUserId, $toUserId);
    }

    /**
     * Deletes a person's whole set.
     *
     * @param int $userId Person
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws DatabaseException When a lookup or a delete fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);

        $this->objectCollection->deleteForUser($userId);
    }
}
