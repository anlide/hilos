<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
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
use Hilos\Hilos;
use Hilos\HilosException;
use Throwable;

/**
 * SecondFactorTrustsActions - write operations for the SecondFactorTrusts collection (HIL-494).
 *
 * Trusting a browser for a person, taking every trust of a person out, and applying the stored
 * trust restrictions to the trusts of one person (HIL-1900), on a request for one of them or at
 * the start of the person's agent (HIL-1901).
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
     * @param int $until Moment the trust runs out (epoch milliseconds)
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function trust(int $sessionId, int $userId, int $until): void
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
     * @param int $limitMs New latest expiry (epoch milliseconds)
     * @param int $savedAtMs Moment the setting was saved (epoch milliseconds)
     * @throws HilosException When ownership, lookup, transaction, or writing fails
     */
    public function capLiveUntil(int $limitMs, int $savedAtMs): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Update);
        $this->writeAtomically(fn () => $this->objectCollection->capLiveUntil($limitMs, $savedAtMs));
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
     * Applies the stored trust restrictions to a person's trusts, in one transaction (HIL-1900).
     *
     * Asked for because of one restriction: when it is not stored the request is refused and
     * nothing is written. Otherwise every restriction saved since the person's earliest grant is
     * applied, the one asked for and any other, and a failed row takes every row back
     * ({@see applySavedRestrictions()}).
     *
     * @param int $userId Person whose trusts the restrictions are applied to
     * @param int $throughId Restriction the request was made for
     * @throws ValidationException When the restriction is not stored
     * @throws WriteNotAllowedException When the truth source rejects an edit or a removal of the person's trusts
     * @throws DatabaseException When a lookup or a write fails
     * @throws HilosException When ownership, the restrictions, the transaction or a row write fails
     */
    public function applyRestrictions(int $userId, int $throughId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        if (!Hilos::$db->secondFactorTrustRestrictions->getObjectCollection()->has($throughId)) {
            throw new ValidationException('Trust restriction ' . $throughId . ' is not saved');
        }

        $this->applySavedRestrictions($userId);
    }

    /**
     * Applies every stored trust restriction to a person's trusts, in one transaction (HIL-1901).
     *
     * Asked for by no restriction in particular - the person's agent at its start - so nothing is
     * checked first. A failed row takes every row back, and with nothing to cut or take out the
     * transaction writes nothing.
     *
     * @param int $userId Person whose trusts the restrictions are applied to
     * @throws WriteNotAllowedException When the truth source rejects an edit or a removal of the person's trusts
     * @throws DatabaseException When a lookup or a write fails
     * @throws HilosException When the restrictions, the transaction or a row write fails
     */
    public function applySavedRestrictions(int $userId): void
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        $this->writeAtomically(fn () => $this->objectCollection->applyRestrictions($userId));
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
            Database::transactionRollbackSilent();
            throw $failure;
        }
    }
}
