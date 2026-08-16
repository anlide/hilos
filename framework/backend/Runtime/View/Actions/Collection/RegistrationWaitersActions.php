<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\RegistrationWaiters as StateRegistrationWaiters;
use Hilos\Runtime\State\Item\RegistrationWaiter as StateRegistrationWaiter;
use Hilos\Runtime\View\Collection\RegistrationWaiters;
use Hilos\Runtime\View\Item\RegistrationWaiter;

/**
 * Write API for the sessions parked on a registration code step (HIL-415).
 *
 * A waiter has two moments and no more: it is parked ({@see park()}) and it is
 * released ({@see release()}) - because the identifier resolved, or because the
 * connection went away and the owning agent's tick reclaims the row. Nothing edits
 * a waiter in place; a session that submits a different address is parked afresh
 * under that one.
 *
 * @extends RtActions<RegistrationWaiter, RegistrationWaiters, StateRegistrationWaiters>
 * @property-read StateRegistrationWaiters $stateCollection
 */
final class RegistrationWaitersActions extends RtActions
{
    /**
     * Parks a connection on the code step of an identifier.
     *
     * Idempotent per connection: submitting again, or resending the code, re-parks
     * the same accept key rather than adding a second row, so a broadcast reaches
     * each connection exactly once. A connection that changed its mind about the
     * address is re-pointed IN PLACE rather than removed and re-added, because the
     * view keeps its items keyed by id: a replaced row would leave the collection
     * answering with an item still bound to the address the person left.
     *
     * @param string $acceptKey Accept key of the waiting connection
     * @param string $identifier Normalized identifier being confirmed (lowercased email)
     * @param string $sessionToken Session token to sign in on confirmation
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
                StateRegistrationWaiter::identifier => $identifier,
                StateRegistrationWaiter::sessionToken => $sessionToken,
            ]);

            return;
        }

        $this->addStateToCollection(StateRegistrationWaiter::create($acceptKey, $identifier, $sessionToken));
    }

    /**
     * Releases one waiting connection, whether or not it was parked.
     *
     * Called once a connection has been told where its step goes. The silent no-op
     * on a missing row is deliberate: a confirmation and an expiry sweep can reach
     * the same connection in the same instant, and both must end with the row gone
     * and neither with an error.
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
