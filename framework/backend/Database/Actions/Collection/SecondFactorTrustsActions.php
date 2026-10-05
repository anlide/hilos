<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorTrusts as ObjectSecondFactorTrusts;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactorTrusts as DbCollectionSecondFactorTrusts;
use Hilos\Database\View\Item\SecondFactorTrust;
use Hilos\HilosException;
use Throwable;

/**
 * SecondFactorTrustsActions - write operations for the SecondFactorTrusts collection (HIL-494).
 *
 * Trusting a browser for a person, and taking every trust of a person out.
 *
 * @extends DbActions<SecondFactorTrust, ObjectSecondFactorTrusts>
 * @property-read DbCollectionSecondFactorTrusts $collection
 * @property-read ObjectSecondFactorTrusts $objectCollection
 */
class SecondFactorTrustsActions extends DbActions
{
    /**
     * Trusts a browser for a person until a moment, writing the pair or moving its end.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param string $until Moment the trust runs out (SQL datetime)
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function trust(int $sessionId, int $userId, string $until): void
    {
        $this->ensureCanCreateInSet((string)$userId);

        $this->objectCollection->trust($sessionId, $userId, $until);
    }

    /**
     * Deletes every trust of a person.
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

    /**
     * Caps trusts that were live when the setting was saved; a retry never extends one.
     *
     * @param string $limitSql New latest expiry (SQL datetime)
     * @param string $savedAtSql Moment the setting was saved (SQL datetime)
     * @throws HilosException When ownership, lookup, transaction, or writing fails
     */
    public function capLiveUntil(string $limitSql, string $savedAtSql): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $this->writeAtomically(fn () => $this->objectCollection->capLiveUntil($limitSql, $savedAtSql));
    }

    /**
     * @throws HilosException When ownership, lookup, transaction, or deletion fails
     */
    public function deleteAll(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->writeAtomically(fn () => $this->objectCollection->deleteAll());
    }

    /**
     * @param int $userId Person whose other browsers lose trust
     * @param int $keepSessionId Current browser's session row
     * @throws HilosException When ownership, lookup, transaction, or deletion fails
     */
    public function deleteForUserExceptSession(int $userId, int $keepSessionId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        $this->writeAtomically(fn () => $this->objectCollection->deleteForUserExceptSession($userId, $keepSessionId));
    }

    /**
     * @param int $sessionId Browser's session row
     * @param int $userId Person whose trust of that browser ends
     * @throws HilosException When ownership, lookup, transaction, or deletion fails
     */
    public function deleteForPair(int $sessionId, int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        $this->writeAtomically(fn () => $this->objectCollection->deleteForPair($sessionId, $userId));
    }

    /**
     * The row writes and their DB-sync announcements stand or fall together.
     *
     * @param callable():void $write Collection write to commit
     * @throws HilosException When the transaction or a row write fails
     */
    private function writeAtomically(callable $write): void
    {
        Database::transactionStart();
        try {
            $write();
            Database::transactionCommit();
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Keep the failure that prevented the trust change.
            }
            throw $failure;
        }
    }
}
