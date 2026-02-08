<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\IdeaActions;

use Demo\Chat\Runtime\Idea\Connection as RuntimeIdeaConnection;
use Demo\Chat\Runtime\State\Connection as StateConnection;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsCallbackNotSetException;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsCollectionNameNullException;
use Hilos\Exception\Runtime\Actions\IdeaRtActionsStateCollectionNullException;
use Hilos\Exception\Runtime\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\Idea\IdeaRtActions;

/**
 * ConnectionsActions - write operations for connections runtime data
 *
 * All modifications to connection data must go through this class.
 *
 * Usage:
 *   Idea::$rt->connections->actions->register($acceptKey, $userId);
 *   Idea::$rt->connections->actions->unregister($acceptKey);
 */
class ConnectionsActions extends IdeaRtActions
{
    /**
     * Register new connection
     *
     * Creates connection state and adds to collection.
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @return RuntimeIdeaConnection Created connection wrapper
     * @throws IdeaRtActionsCallbackNotSetException If callback for creating IdeaRtItem is not set
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    public function register(string $acceptKey, int $userId): RuntimeIdeaConnection
    {
        // Create state
        $state = StateConnection::create($acceptKey, $userId);

        // Add to collection
        $this->addStateToCollection($state);

        // Return IdeaRtItem wrapper
        return $this->createRtItemFromState($state);
    }

    /**
     * Unregister connection
     *
     * Removes connection from collection.
     *
     * @param string $acceptKey WebSocket accept key
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    public function unregister(string $acceptKey): void
    {
        $this->removeStateFromCollection($acceptKey);
    }

    /**
     * Clear all connections
     *
     * Removes all connections from collection.
     *
     * @throws IdeaRtActionsStateCollectionNullException If state collection is null
     * @throws IdeaRtActionsCallbackNotSetException If callback is not set
     * @throws IdeaRtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }
}
