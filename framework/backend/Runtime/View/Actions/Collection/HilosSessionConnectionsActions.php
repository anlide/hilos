<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;
use Hilos\Runtime\View\Collection\HilosSessionConnections;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\Runtime\View\Item\HilosSessionConnection;

/**
 * Write API for the connections runtime collection — the session stage (HIL-509).
 *
 * Two writes differ from {@see HilosConnectionsActions}: the row a socket opens with also
 * names the session it belongs to, and a row can be moved onto a renamed session (HIL-582).
 * A third used to mark the row with the ack its surface owed the person (HIL-422). That mark
 * moved to the session row in HIL-875, because what it announces is about the account and a
 * socket is the wrong lifetime for it: the socket dies under a person who has not read it,
 * and it survives a person who has stopped being one. There is still no session-stage item
 * actions class beside this one, because both writes are addressed by accept key rather than
 * held per row.
 *
 * @template TItem of HilosSessionConnection
 * @template TCollection of HilosSessionConnections
 * @extends HilosConnectionsActions<TItem, TCollection>
 */
abstract class HilosSessionConnectionsActions extends HilosConnectionsActions
{
    /**
     * Adds a connection row for a new socket of a session.
     *
     * @param string $acceptKey WebSocket accept key (connection id)
     * @param ?int $userId Authenticated user id, or null for an anonymous session
     * @param ?string $sessionToken Session cookie token this socket belongs to, or null when it belongs to none
     * @return HilosConnection View item for the new connection
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsItemClassException When the item factory returns a non-connection item
     * @throws RtActionsStateClassException When the mounted collection names a non-session state class
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function register(string $acceptKey, ?int $userId, ?string $sessionToken = null): HilosConnection
    {
        /** @var class-string<StateHilosSessionConnection> $stateClass */
        $stateClass = $this->connectionStateClass(StateHilosSessionConnection::class);
        $state = $stateClass::create($acceptKey, $userId, $sessionToken);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Moves one live connection onto the token its session was renamed to (HIL-582).
     *
     * The narrow companion to the rule the row states: a socket that changes session is
     * a new row, and that has not changed. What this covers is the other case — the
     * session did not change, its secret name did, because the login rotated the token
     * out from under a value someone may have known. The socket stays with the session
     * it always belonged to.
     *
     * Called for the connection that initiated the login and no other. The session's
     * remaining sockets are not re-pointed: they are dropped once the browser holds the
     * new cookie, and they come back naming the rotated session themselves.
     *
     * A connection this collection does not hold is a silent no-op — the socket died
     * between the login and this write, which is exactly the outcome a dead socket
     * should have.
     *
     * @param string $acceptKey Accept key of the connection to re-point
     * @param string $newToken Session token the row now belongs to
     *
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the project's read of its own connection fields raises
     */
    public function repointSessionToken(string $acceptKey, string $newToken): void
    {
        $this->ensureCanWrite();

        $state = $this->getStateCollection()->get($acceptKey);
        if ($state === null) {
            return;
        }

        $this->applyDiffToState($state, [StateHilosSessionConnection::sessionToken => $newToken]);
    }
}
