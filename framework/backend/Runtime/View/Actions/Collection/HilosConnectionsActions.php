<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Item\HilosConnection;

/**
 * Write API for the connections runtime collection — the presence stage (HIL-509).
 *
 * The two writes every project makes to its connections: a row per opened socket
 * and a full reset. The row is built through the state class the mounted
 * collection names, so this base never has to know the project's concrete row —
 * which is the whole reason it can live in the framework at all.
 *
 * @template TItem of HilosConnection
 * @template TCollection of HilosConnections
 * @extends RtActions<TItem, TCollection>
 */
abstract class HilosConnectionsActions extends RtActions
{
    /**
     * Adds a connection row for a new socket.
     *
     * @param string $acceptKey WebSocket accept key (connection id)
     * @param ?int $userId Authenticated user id, or null for an anonymous connection
     * @return HilosConnection View item for the new connection
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsItemClassException When the item factory returns a non-connection item
     * @throws RtActionsStateClassException When the mounted collection names a non-connection state class
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     * @throws HilosException Whatever a subscriber to the collection's announcement raises
     */
    public function register(string $acceptKey, ?int $userId): HilosConnection
    {
        $stateClass = $this->connectionStateClass(StateHilosConnection::class);
        $state = $stateClass::create($acceptKey, $userId);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Removes every connection from runtime (full reset).
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }

    /**
     * Names the state class the mounted collection builds rows with, refusing one
     * that does not reach the stage whose factory the caller is about to call.
     *
     * The stage is passed in rather than assumed because the call that would
     * otherwise be lost is silent: PHP drops arguments a function does not
     * declare, so a session token handed to a presence-stage row would simply
     * never be stored, and the loss would surface as a socket belonging to no
     * session. The opposite mis-wiring — a session-stage row registered through
     * these presence-stage actions — is not caught here and cannot be: refusing
     * it would mean this stage naming the stage above it. It is a project
     * pairing the wrong actions class with its collection, and the row it builds
     * carries a null token.
     *
     * @param class-string<StateHilosConnection> $stage Stage whose factory the caller is about to call
     * @return class-string<StateHilosConnection> State class of the mounted collection
     *
     * @throws RtActionsStateClassException When the mounted collection's state class does not reach the stage
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    protected function connectionStateClass(string $stage): string
    {
        $stateCollection = $this->getStateCollection();
        $stateClass = $stateCollection::STATE_CLASS;
        if (!is_subclass_of($stateClass, $stage)) {
            throw new RtActionsStateClassException(
                'Runtime collection ' . $stateCollection::class . " names state class [{$stateClass}], "
                . "which does not extend {$stage}"
            );
        }

        /** @var class-string<StateHilosConnection> $stateClass */
        return $stateClass;
    }

    /**
     * Narrows the parent return type to a framework connection item.
     *
     * @param RtState $state State instance
     * @return HilosConnection View item for the connection state
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is not configured
     * @throws RtActionsItemClassException When the item factory returns a non-connection item
     */
    protected function createRtItemFromState(RtState $state): HilosConnection
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof HilosConnection) {
            throw new RtActionsItemClassException(
                'Connections item factory must return ' . HilosConnection::class
            );
        }

        return $item;
    }
}
