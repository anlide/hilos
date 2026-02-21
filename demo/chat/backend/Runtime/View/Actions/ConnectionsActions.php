<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions;

use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\RtActions;

/**
 * ConnectionsActions - write operations for connections runtime data.
 *
 * All modifications to connection data must go through this class.
 *
 * Usage:
 *   Hilos::$rt->connections->actions->register($acceptKey, $userId);
 *   Hilos::$rt->connections->actions->unregister($acceptKey);
 */
class ConnectionsActions extends RtActions
{
    /**
     * Register new connection.
     *
     * @param string $acceptKey WebSocket accept key
     * @param int $userId User ID
     * @return RuntimeConnection Created connection wrapper
     * @throws RtActionsCallbackNotSetException If callback for creating Rt item is not set
     * @throws RtActionsStateCollectionNullException If state collection is null
     * @throws RtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    public function register(string $acceptKey, int $userId): RuntimeConnection
    {
        $state = StateConnection::create($acceptKey, $userId);
        $this->addStateToCollection($state);
        return $this->createRtItemFromState($state);
    }

    /**
     * Unregister connection.
     *
     * @param string $acceptKey WebSocket accept key
     * @throws RtActionsStateCollectionNullException If state collection is null
     * @throws RtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    public function unregister(string $acceptKey): void
    {
        $this->removeStateFromCollection($acceptKey);
    }

    /**
     * Clear all connections.
     *
     * @throws RtActionsStateCollectionNullException If state collection is null
     * @throws RtActionsCallbackNotSetException If callback is not set
     * @throws RtActionsCollectionNameNullException If collection name is null
     * @throws RtTruthSourceWriteNotAllowedException If no truth source registered
     */
    public function clear(): void
    {
        $this->clearAllStates();
    }
}
