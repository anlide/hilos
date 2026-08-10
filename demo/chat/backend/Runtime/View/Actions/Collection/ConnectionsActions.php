<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Actions\Collection;

use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;

/**
 * Write API for the active WebSocket connections runtime collection.
 *
 * Registering a socket of a session and clearing the collection are the
 * framework's own writes; what is left here is chat's own collection-wide write.
 * Per-socket file upload UI writes go through connection item actions.
 *
 * @extends HilosSessionConnectionsActions<RuntimeConnection, Connections>
 */
final class ConnectionsActions extends HilosSessionConnectionsActions
{
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
