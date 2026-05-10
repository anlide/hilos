<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\State\Collection\Connections as StateConnections;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
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
 * Per-socket file upload UI writes go through connection item actions.
 *
 * @extends RtActions<RuntimeConnection, Connections, StateConnections>
 * @property-read StateConnections $stateCollection
 */
final class ConnectionsActions extends RtActions
{
    /**
     * Add a connection row for a new socket.
     *
     * @param string $acceptKey WebSocket accept key (connection id)
     * @param int $userId Chat user id for this socket
     * @return RuntimeConnection View item for the new connection
     *
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function register(string $acceptKey, int $userId): RuntimeConnection
    {
        $state = StateConnection::create($acceptKey, $userId);
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Narrows parent return type to this collection's RtItem.
     *
     * @param RtState $state State instance (reference)
     * @return RuntimeConnection
     *
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
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
     * Remove every connection from runtime (full reset).
     *
     * @throws RtActionsCallbackNotSetException When runtime item factory callback is not configured
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }

    /**
     * Clear file session and upload progress on each connection (e.g. after disk wipe).
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function clearAllFileRuntimeOnAllConnections(): void
    {
        $this->ensureCanWrite();
        foreach ($this->collection as $connection) {
            $connection->actions->clearAllFileRuntimeOnSocket();
        }
    }
}
