<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
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
 * the start of the person's agent (HIL-1901). The session holder creates a trust a pair has none
 * of yet and leaves a standing one as it is; the person's agent extends a standing trust to the
 * moment the second factor was confirmed again and applies the restrictions with it (HIL-1907).
 *
 * @extends DbActions<SecondFactorTrust, ObjectSecondFactorTrusts>
 * @property-read DbCollectionSecondFactorTrusts $collection
 * @property-read ObjectSecondFactorTrusts $objectCollection
 */
class SecondFactorTrustsActions extends DbActions
{
    /**
     * Trusts a browser for a person until a moment, when the pair has no trust yet (HIL-1907).
     *
     * A standing pair is left as it is and nothing is written ({@see ObjectSecondFactorTrusts::trust()}):
     * the session holder that creates trusts on the way in asks the person's agent to extend it.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param int $until Moment the trust runs out (epoch milliseconds)
     * @param int $grantedAt Moment the second factor was confirmed with the trust asked for (epoch milliseconds)
     * @return bool Whether the pair was written now; false when it stands already
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws ExternalWriterException When the mounted Entity declares an external database writer
     * @throws DatabaseException When the lookup or the write fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     */
    public function trust(int $sessionId, int $userId, int $until, int $grantedAt): bool
    {
        $this->ensureCanCreateInSet((string)$userId);

        return $this->objectCollection->trust($sessionId, $userId, $until, $grantedAt);
    }

    /**
     * Extends the standing trust of a browser for a person, and applies the stored restrictions to the person, in one transaction (HIL-1907).
     *
     * The person's agent extends the pair the session holder found standing at the confirmation
     * ({@see ObjectSecondFactorTrusts::renew()}). While the pair stands, every stored restriction is
     * applied to the person's trusts in the same transaction, so one saved at the confirmation or
     * later cuts or takes out the extended pair at once ({@see applySavedRestrictions()}). With no
     * pair nothing is written, and with nothing to cut or take out the application writes nothing.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person the browser is trusted for
     * @param int $until Moment the trust runs out (epoch milliseconds)
     * @param int $grantedAt Moment the second factor was confirmed with the trust asked for (epoch milliseconds)
     * @return bool Whether the pair stands - extended now or granted later already; false when there is none
     * @throws WriteNotAllowedException When the truth source rejects an edit or a removal of the person's trusts
     * @throws DatabaseException When a lookup or a write fails
     * @throws HilosException When the restrictions, the transaction or a row write fails
     */
    public function renew(int $sessionId, int $userId, int $until, int $grantedAt): bool
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Remove);
        $renewed = false;
        $this->writeAtomically(function () use ($sessionId, $userId, $until, $grantedAt, &$renewed): void {
            $renewed = $this->objectCollection->renew($sessionId, $userId, $until, $grantedAt);
            if ($renewed) {
                $this->objectCollection->applyRestrictions($userId);
            }
        });

        return $renewed;
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
