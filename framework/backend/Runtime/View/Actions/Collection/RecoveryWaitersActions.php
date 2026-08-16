<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\RecoveryWaiters as StateRecoveryWaiters;
use Hilos\Runtime\State\Item\RecoveryWaiter as StateRecoveryWaiter;
use Hilos\Runtime\View\Collection\RecoveryWaiters;
use Hilos\Runtime\View\Item\RecoveryWaiter;

/**
 * Write API for the sessions parked on a password-recovery code step (HIL-416).
 *
 * A recovery waiter has one moment more than a registration one, and that moment is
 * the whole difference between the two flows: it is parked when a code goes out
 * ({@see park()}), it is GRANTED when a code comes back ({@see acceptCodeForSession()}),
 * and it is released ({@see release()}) - because somebody saved a new password, or
 * because the connection went away and the owning agent's tick reclaims the row.
 *
 * The grant is addressed by session and not by connection on purpose: proving a code
 * in one tab opens the password step in every tab of that session and in no tab of
 * anyone else's, which is the session-binding this flow is named for.
 *
 * @extends RtActions<RecoveryWaiter, RecoveryWaiters, StateRecoveryWaiters>
 * @property-read StateRecoveryWaiters $stateCollection
 */
final class RecoveryWaitersActions extends RtActions
{
    /**
     * Parks a connection on the code step of a recovery.
     *
     * Idempotent per connection: asking again, or re-sending the code, re-parks the
     * same accept key rather than adding a second row, so a broadcast reaches each
     * connection exactly once. A connection that changed its mind about the address
     * is re-pointed IN PLACE rather than removed and re-added, because the view keeps
     * its items keyed by id - and the grant goes with the address it was earned for:
     * re-parking clears `codeAccepted`, since the code that was proven belonged to
     * the identifier the person just left.
     *
     * @param string $acceptKey Accept key of the waiting connection
     * @param string $identifier Normalized identifier being recovered (lowercased email)
     * @param string $sessionToken Session token the grant is bound to
     * @throws RtActionsCallbackNotSetException When the collection's forget-cached-item callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the concrete state's read of the diff raises
     */
    public function park(string $acceptKey, string $identifier, string $sessionToken): void
    {
        $this->ensureCanWrite();

        $parked = $this->stateCollection->get($acceptKey);
        if ($parked !== null) {
            $this->applyDiffToState($parked, [
                StateRecoveryWaiter::identifier => $identifier,
                StateRecoveryWaiter::sessionToken => $sessionToken,
                StateRecoveryWaiter::codeAccepted => false,
            ]);

            return;
        }

        $this->addStateToCollection(StateRecoveryWaiter::create($acceptKey, $identifier, $sessionToken));
    }

    /**
     * Grants one session the password step of ONE address, and of no other.
     *
     * What an accepted code buys, and the reason the code is checked without being
     * spent: the person moves from the code screen to the password screen, and the
     * proof that they may has to outlive the action that established it. It is
     * written on the session's rows rather than on the answering connection's,
     * because a session is its tabs - refusing the second tab would be refusing the
     * same person.
     *
     * The identifier is half the grant, not decoration. A session is its tabs, and two
     * tabs may be parked on two DIFFERENT addresses; granting by session alone would
     * let a code proven for one address open the password step of the other, which is
     * an account takeover with no code involved. So the address that was proven is
     * granted and every other address of the session is un-granted in the same pass:
     * a session is on one recovery at a time, and the last code it proved says which.
     *
     * A session with no parked row is a silent no-op: the caller has already answered
     * the code, and there is nothing here that could make that answer wrong.
     *
     * @param string $sessionToken Session token that just proved the code
     * @param string $identifier Normalized address the code was proven for (lowercased email)
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the concrete state's read of the diff raises
     */
    public function acceptCodeForSession(string $sessionToken, string $identifier): void
    {
        $this->ensureCanWrite();

        foreach ($this->stateCollection->findAllBySessionToken($sessionToken) as $parked) {
            $granted = $parked->identifier === $identifier;
            if ($parked->codeAccepted === $granted) {
                continue;
            }

            $this->applyDiffToState($parked, [StateRecoveryWaiter::codeAccepted => $granted]);
        }
    }

    /**
     * Releases one waiting connection, whether or not it was parked.
     *
     * Called once a connection has been told where its step goes. The silent no-op on
     * a missing row is deliberate: a completed reset and an expiry sweep can reach the
     * same connection in the same instant, and both must end with the row gone and
     * neither with an error.
     *
     * @param string $acceptKey Accept key of the connection to release
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function release(string $acceptKey): void
    {
        $this->ensureCanWrite();

        if ($this->stateCollection->get($acceptKey) === null) {
            return;
        }

        $this->removeStateFromCollection($acceptKey);
    }
}
