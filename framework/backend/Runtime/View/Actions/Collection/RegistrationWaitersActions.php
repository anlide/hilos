<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
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
 * connection went away and the owning agent's tick reclaims the row.
 *
 * A session that submits a different address is re-pointed rather than parked afresh,
 * and only the session holder does it ({@see repoint()}): editing a row that is already
 * there belongs to this collection's one full truth source, and the users library, which
 * may add and remove, says the rest in a frame (HIL-685).
 *
 * @extends RtActions<RegistrationWaiter, RegistrationWaiters, StateRegistrationWaiters>
 * @property-read StateRegistrationWaiters $stateCollection
 */
final class RegistrationWaitersActions extends RtActions
{
    /**
     * Parks a connection on the code step of an identifier, unless it is parked already.
     *
     * Idempotent per connection: submitting again, or resending the code, leaves the one
     * row of that accept key where it is rather than adding a second, so a broadcast
     * reaches each connection exactly once.
     *
     * What it no longer does is re-point a row that is already there (HIL-685). Adding
     * and removing is the whole of what the users library may do to this collection -
     * the session holder is its one FULL truth source - so the three cases a caller can
     * meet end in two outcomes here: no row is added, a row that says the same thing is
     * left alone, and a row that says something else is not written at all. The last one
     * is not lost: the library sends {@see HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED}
     * beside this call and the holder does the edit in {@see repoint()}.
     *
     * @param string $acceptKey Accept key of the waiting connection
     * @param string $identifier Normalized identifier being confirmed (lowercased email)
     * @param string $sessionToken Session token to sign in on confirmation
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function park(string $acceptKey, string $identifier, string $sessionToken): void
    {
        $this->ensureCanWrite();

        if ($this->stateCollection->get($acceptKey) !== null) {
            return;
        }

        $this->addStateToCollection(StateRegistrationWaiter::create($acceptKey, $identifier, $sessionToken));
    }

    /**
     * Points one waiting connection at the address it waits on NOW.
     *
     * The holder's half of {@see park()}, and the reason the pair is split (HIL-685):
     * this one edits a row that already exists, and editing this collection belongs to
     * its full truth source alone. It runs off the frame the users library sends
     * whenever it parks a browser, so the person who went back and submitted another
     * address is re-pointed IN PLACE rather than removed and re-added: the view keeps
     * its items keyed by id, and a replaced row would leave the collection answering
     * with an item still bound to the address the person left.
     *
     * A frame that says exactly what the row already says writes nothing - which is what
     * makes it safe for the library to send one on every park.
     *
     * @param string $acceptKey Accept key of the waiting connection
     * @param string $identifier Normalized identifier being confirmed (lowercased email)
     * @param string $sessionToken Session token to sign in on confirmation
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the concrete state's read of the diff raises
     */
    public function repoint(string $acceptKey, string $identifier, string $sessionToken): void
    {
        $this->ensureCanWrite();

        $parked = $this->stateCollection->get($acceptKey);
        if ($parked === null) {
            $this->addStateToCollection(StateRegistrationWaiter::create($acceptKey, $identifier, $sessionToken));

            return;
        }

        if ($parked->identifier === $identifier && $parked->sessionToken === $sessionToken) {
            return;
        }

        $this->applyDiffToState($parked, [
            StateRegistrationWaiter::identifier => $identifier,
            StateRegistrationWaiter::sessionToken => $sessionToken,
        ]);
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
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
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
