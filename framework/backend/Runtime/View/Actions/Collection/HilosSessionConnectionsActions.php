<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

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
 * One write differs from {@see HilosConnectionsActions}: the row a socket opens
 * with also names the session it belongs to. There is no session-stage item
 * actions class beside this one, because the token is written when the row is
 * created and never after: a socket that changes session is a new socket.
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
     */
    public function register(string $acceptKey, ?int $userId, ?string $sessionToken = null): HilosConnection
    {
        /** @var class-string<StateHilosSessionConnection> $stateClass */
        $stateClass = $this->connectionStateClass(StateHilosSessionConnection::class);
        $state = $stateClass::create($acceptKey, $userId, $sessionToken);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }
}
