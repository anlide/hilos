<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Runtime\View\Actions\Collection;

use Demo\SimplePoll\Runtime\State\Collection\Connections as StateConnections;
use Demo\SimplePoll\Runtime\State\Item\Connection as StateConnection;
use Demo\SimplePoll\Runtime\View\Collection\Connections;
use Demo\SimplePoll\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use LogicException;

/**
 * Write API for the active WebSocket connections runtime collection.
 *
 * @extends RtActions<RuntimeConnection, Connections, StateConnections>
 * @property-read StateConnections $stateCollection
 */
final class ConnectionsActions extends RtActions
{
    /**
     * Adds a connection row for a new socket.
     *
     * @param string $acceptKey WebSocket accept key (connection id)
     * @param int $userId User id for this socket
     * @return RuntimeConnection View item for the new connection
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     */
    public function register(string $acceptKey, int $userId): RuntimeConnection
    {
        $state = StateConnection::create($acceptKey, $userId);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Narrows the parent return type to this collection's RtItem.
     *
     * @param RtState $state State instance (reference)
     * @return RuntimeConnection View item for the connection state
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is not configured
     */
    protected function createRtItemFromState(RtState &$state): RuntimeConnection
    {
        $item = parent::createRtItemFromState($state);
        if (!$item instanceof RuntimeConnection) {
            throw new LogicException('Connections item factory must return ' . RuntimeConnection::class);
        }

        return $item;
    }

    /**
     * Removes every connection from runtime (full reset).
     *
     * @throws RtActionsCallbackNotSetException When the runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }
}
